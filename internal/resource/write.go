package resource

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/jackc/pgx/v5"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

// Input modes: POST creates, PUT replaces (required properties enforced),
// PATCH updates only the given keys.
const (
	ModeCreate  = "create"
	ModeReplace = "replace"
	ModePatch   = "patch"
)

var linkedDataKeys = []string{"@context", "@id", "@type"}

// ValidateInput checks a decoded JSON body against the input properties
// (recursively for object and array inputs) and returns it with values
// converted to their Go types. Errors carry JSON pointers into the body.
func ValidateInput(d *Definition, mode string, body map[string]any, id any) (map[string]any, apierr.List) {
	var errs apierr.List
	out := map[string]any{}
	if d.IgnoresBody() {
		return out, nil
	}
	inputs := d.InputProps()
	writable := propertyNames(inputs)
	for key, v := range body {
		if strings.HasPrefix(key, "@") {
			continue
		}
		p, ok := d.InputProp(key)
		if !ok {
			if d.IgnoreUnknownInput {
				continue
			}
			if op, isOutput := d.Property(key); isOutput && op.ReadOnly && d.Input == nil {
				errs = append(errs, &apierr.Error{Code: apierr.ReadOnlyProperty, Resource: d.Name, Pointer: "/" + key, Message: fmt.Sprintf("%q is read-only", key), Hint: "Remove it from the body. Accepted keys: " + strings.Join(writable, ", ") + "."})
				continue
			}
			errs = append(errs, unknownKey(d, key, "/"+key, writable))
			continue
		}
		cv, verrs := validateValue(d, p, v, "/"+key)
		if len(verrs) > 0 {
			errs = append(errs, verrs...)
			continue
		}
		if p.Identifier && mode != ModeCreate && id != nil && fmt.Sprint(cv) != fmt.Sprint(id) {
			errs = append(errs, &apierr.Error{Code: apierr.InvalidPropertyValue, Resource: d.Name, Pointer: "/" + key, Message: fmt.Sprintf("%q is %v in the body but %v in the URL", key, v, id), Hint: "The identifier cannot be changed; omit it from the body."})
			continue
		}
		out[key] = cv
	}
	if mode == ModeCreate || mode == ModeReplace {
		for _, p := range inputs {
			if _, ok := body[p.Name]; p.Required && !ok {
				errs = append(errs, &apierr.Error{Code: apierr.RequiredProperty, Resource: d.Name, Pointer: "/" + p.Name, Message: fmt.Sprintf("%q is required", p.Name), Hint: fmt.Sprintf("Send %q (%s). PATCH accepts partial bodies.", p.Name, p.Type)})
			}
		}
	}
	errs.Sort()
	return out, errs
}

func unknownKey(d *Definition, key, ptr string, accepted []string) *apierr.Error {
	hint := "Accepted keys: " + strings.Join(accepted, ", ") + "."
	if d.Input != nil {
		hint = "The body is validated against \"input\". " + hint
	}
	if s := apierr.Suggest(key, accepted); s != "" {
		hint = fmt.Sprintf("Did you mean %q? ", s) + hint
	}
	return &apierr.Error{Code: apierr.UnknownProperty, Resource: d.Name, Pointer: ptr, Message: fmt.Sprintf("%q is not a property of %s", key, d.Name), Hint: hint}
}

func validateValue(d *Definition, p Property, v any, ptr string) (any, apierr.List) {
	invalid := func(msg, hint string) apierr.List {
		return apierr.List{{Code: apierr.InvalidPropertyValue, Resource: d.Name, Pointer: ptr, Message: msg, Hint: hint}}
	}
	if v == nil {
		if p.Nullable != nil && !*p.Nullable {
			return nil, invalid(fmt.Sprintf("%q must not be null", p.Name), valueHint(p.Type))
		}
		return nil, nil
	}
	switch p.Type {
	case "object":
		m, ok := v.(map[string]any)
		if !ok {
			return nil, invalid(fmt.Sprintf("%q must be an object, got %s", p.Name, describe(v)), "Send a JSON object.")
		}
		return validateObject(d, p, m, ptr)
	case "array":
		list, ok := v.([]any)
		if !ok {
			return nil, invalid(fmt.Sprintf("%q must be an array, got %s", p.Name, describe(v)), "Send a JSON array.")
		}
		if p.MinItems != nil && len(list) < *p.MinItems {
			return nil, invalid(fmt.Sprintf("%q must have at least %d element(s), got %d", p.Name, *p.MinItems, len(list)), "")
		}
		if p.MaxItems != nil && len(list) > *p.MaxItems {
			return nil, invalid(fmt.Sprintf("%q must have at most %d element(s), got %d", p.Name, *p.MaxItems, len(list)), "")
		}
		out := make([]any, len(list))
		var errs apierr.List
		for i, e := range list {
			if p.Items == nil {
				out[i] = e
				continue
			}
			item := *p.Items
			item.Name = fmt.Sprintf("%s[%d]", p.Name, i)
			cv, ierrs := validateValue(d, item, e, fmt.Sprintf("%s/%d", ptr, i))
			errs = append(errs, ierrs...)
			out[i] = cv
		}
		if len(errs) > 0 {
			return nil, errs
		}
		return out, nil
	}
	cv, err := coerce(p, v)
	if err != nil {
		return nil, invalid(fmt.Sprintf("%q must be %s, got %s", p.Name, article(p.Type), describe(v)), valueHint(p.Type))
	}
	if t, isTime := cv.(time.Time); isTime && p.Type == "date" {
		cv = t.Format("2006-01-02")
	}
	switch x := cv.(type) {
	case string:
		n := utf8.RuneCountInString(x)
		if p.MinLength != nil && n < *p.MinLength {
			return nil, invalid(fmt.Sprintf("%q must have at least %d character(s)", p.Name, *p.MinLength), "")
		}
		if p.MaxLength != nil && n > *p.MaxLength {
			return nil, invalid(fmt.Sprintf("%q must have at most %d character(s)", p.Name, *p.MaxLength), "")
		}
	case int64, float64:
		f, _ := toFloat(x)
		if p.Min != nil && f < *p.Min {
			return nil, invalid(fmt.Sprintf("%q must be at least %s, got %v", p.Name, num(*p.Min), x), "")
		}
		if p.Max != nil && f > *p.Max {
			return nil, invalid(fmt.Sprintf("%q must be at most %s, got %v", p.Name, num(*p.Max), x), "")
		}
	}
	if len(p.Enum) > 0 && !slices.ContainsFunc(p.Enum, func(e any) bool { return fmt.Sprint(e) == fmt.Sprint(cv) }) {
		allowed := make([]string, len(p.Enum))
		for i, e := range p.Enum {
			allowed[i] = fmt.Sprint(e)
		}
		return nil, invalid(fmt.Sprintf("%q must be one of %s, got %v", p.Name, strings.Join(allowed, ", "), cv), "")
	}
	return cv, nil
}

func article(typ string) string {
	if typ == "integer" {
		return "an integer"
	}
	return "a " + typ
}

func toFloat(v any) (float64, bool) {
	switch x := v.(type) {
	case int64:
		return float64(x), true
	case float64:
		return x, true
	}
	return 0, false
}

func validateObject(d *Definition, p Property, m map[string]any, ptr string) (any, apierr.List) {
	out := map[string]any{}
	var errs apierr.List
	accepted := propertyNames(p.Properties)
	for key, v := range m {
		sub, ok := p.Sub(key)
		if !ok {
			if !d.IgnoreUnknownInput && len(p.Properties) > 0 {
				errs = append(errs, unknownKey(d, key, ptr+"/"+escapePointer(key), accepted))
			} else if len(p.Properties) == 0 {
				out[key] = v
			}
			continue
		}
		cv, serrs := validateValue(d, sub, v, ptr+"/"+escapePointer(key))
		errs = append(errs, serrs...)
		out[key] = cv
	}
	for _, sub := range p.Properties {
		if _, ok := m[sub.Name]; sub.Required && !ok {
			errs = append(errs, &apierr.Error{Code: apierr.RequiredProperty, Resource: d.Name, Pointer: ptr + "/" + escapePointer(sub.Name), Message: fmt.Sprintf("%q is required", sub.Name), Hint: fmt.Sprintf("Send %q (%s) in each %s.", sub.Name, sub.Type, p.Name)})
		}
	}
	if len(errs) > 0 {
		return nil, errs
	}
	return out, nil
}

// InputColumn is the column a generated INSERT/UPDATE writes an input to.
func (d *Definition) InputColumn(p Property) string {
	if p.Column != "" {
		return p.Column
	}
	if out, ok := d.Property(p.Name); ok {
		return out.ColumnName()
	}
	return defaultColumn(p.Name)
}

func valueHint(typ string) string {
	switch typ {
	case "integer":
		return "Send a JSON integer, e.g. 42 (not \"42\")."
	case "number":
		return "Send a JSON number, e.g. 4.5."
	case "boolean":
		return "Send true or false (JSON booleans, not strings)."
	case "datetime":
		return "Send an RFC 3339 string such as \"2024-01-31T12:00:00Z\" or a date \"2024-01-31\"."
	case "date":
		return "Send a date string \"YYYY-MM-DD\"."
	}
	return "Send a JSON string."
}

// Write runs the command (or generated SQL) in a transaction and returns the
// identifier of the written row.
func (r *Runtime) Write(ctx context.Context, d *Definition, op string, req script.Request, id any, input map[string]any, ex *Exec) (any, error) {
	tx, err := r.db().Begin(ctx)
	if err != nil {
		return nil, apierr.FromPg(err, apierr.PgContext{Resource: d.Name})
	}
	defer func() { _ = tx.Rollback(context.Background()) }()
	newID, err := r.writeOne(ctx, tx, d, op, req, id, input, ex)
	var raw *RawResponse
	if errors.As(err, &raw) {
		if err := tx.Commit(ctx); err != nil {
			return nil, apierr.FromPg(err, apierr.PgContext{Resource: d.Name})
		}
		return nil, raw
	}
	if err != nil {
		return nil, err
	}
	if err := tx.Commit(ctx); err != nil {
		return nil, apierr.FromPg(err, apierr.PgContext{Resource: d.Name})
	}
	return newID, nil
}

func (r *Runtime) writeOne(ctx context.Context, tx pgx.Tx, d *Definition, op string, req script.Request, id any, input map[string]any, ex *Exec) (any, error) {
	if d.CommandMod != nil {
		fn := CommandExport(d.CommandMod, op)
		call := r.call(d, req, op, "write", ex, "/command", tx)
		call.ID, call.Input = id, input
		call.RawAllowed = d.Current != nil && d.Current.IsItem() && id != nil
		v, err := d.CommandMod.Call(ctx, fn, call)
		if err != nil {
			if e, ok := apierr.As(err); ok && e.Resource == "" {
				e.Resource = d.Name
			}
			return nil, err
		}
		if resp, ok := v.(*script.Response); ok {
			return nil, &RawResponse{resp}
		}
		if op != "create" || id != nil {
			return id, nil
		}
		if v == nil {
			return nil, &apierr.Error{Code: apierr.InvalidQueryResult, Resource: d.Name, Pointer: "/command", Message: d.Command + ": create returned nothing", Hint: "create must return the identifier of the new row, e.g. `const [row] = ctx.db.query(ctx.sql`INSERT ... RETURNING id`); return row.id;`."}
		}
		return v, nil
	}
	return r.generated(ctx, tx, d, op, id, input)
}

func (r *Runtime) generated(ctx context.Context, tx pgx.Tx, d *Definition, op string, id any, input map[string]any) (any, error) {
	idCol := sqlf.Ident(d.Identifier().Name)
	table := sqlf.TableIdent(d.Table)
	var cols []string
	var args []any
	for _, p := range d.InputProps() {
		if v, ok := input[p.Name]; ok {
			cols = append(cols, d.InputColumn(p))
			args = append(args, v)
		}
	}
	var sql string
	switch op {
	case "create":
		if len(cols) == 0 {
			sql = "INSERT INTO " + table + " DEFAULT VALUES RETURNING " + idCol
		} else {
			quoted, ph := make([]string, len(cols)), make([]string, len(cols))
			for i, c := range cols {
				quoted[i], ph[i] = sqlf.Ident(c), fmt.Sprintf("$%d", i+1)
			}
			sql = "INSERT INTO " + table + " (" + strings.Join(quoted, ", ") + ") VALUES (" + strings.Join(ph, ", ") + ") RETURNING " + idCol
		}
		var newID any
		if err := tx.QueryRow(ctx, sql, args...).Scan(&newID); err != nil {
			return nil, writeErr(err, d, sql)
		}
		return toJSON(d.Identifier().Type, newID), nil
	case "update":
		if len(cols) == 0 {
			return id, nil
		}
		sets := make([]string, len(cols))
		for i, c := range cols {
			sets[i] = fmt.Sprintf("%s = $%d", sqlf.Ident(c), i+1)
		}
		sql = "UPDATE " + table + " SET " + strings.Join(sets, ", ") + fmt.Sprintf(" WHERE %s = $%d", idCol, len(cols)+1)
		args = append(args, id)
	case "delete":
		sql = "DELETE FROM " + table + " WHERE " + idCol + " = $1"
		args = []any{id}
	}
	if _, err := tx.Exec(ctx, sql, args...); err != nil {
		return nil, writeErr(err, d, sql)
	}
	return id, nil
}

func writeErr(err error, d *Definition, sql string) error {
	return apierr.FromPg(err, apierr.PgContext{Resource: d.Name, Pointer: "/table", SQL: sql, Body: true})
}

// Run executes a write operation declared in "operations": the command
// function for its method runs in a transaction and may return a row, which
// becomes the response body.
func (r *Runtime) Run(ctx context.Context, d *Definition, op Operation, req script.Request, input map[string]any, ex *Exec) (Item, error) {
	fnOp := map[string]string{"POST": "create", "PUT": "update", "PATCH": "update", "DELETE": "delete"}[op.Method]
	fn := CommandExport(d.CommandMod, fnOp)
	if fn == "" {
		name := fnOp
		if name == "delete" {
			name = "remove"
		}
		return nil, &apierr.Error{Code: apierr.OperationNotSupported, Resource: d.Name, Pointer: "/command", Message: fmt.Sprintf("%s does not define %s, needed by %s %s", d.Command, name, op.Method, op.Path), Hint: fmt.Sprintf("Add a %s(input, ctx) function to %s.", name, d.Command)}
	}
	tx, err := r.db().Begin(ctx)
	if err != nil {
		return nil, apierr.FromPg(err, apierr.PgContext{Resource: d.Name})
	}
	defer func() { _ = tx.Rollback(context.Background()) }()
	call := r.call(d, req, fnOp, "write", ex, "/command", tx)
	call.Input = input
	call.RawAllowed = d.Current != nil
	v, err := d.CommandMod.Call(ctx, fn, call)
	if err != nil {
		return nil, tag(err, d, "/command")
	}
	var item Item
	switch x := v.(type) {
	case *script.Response:
		if err := tx.Commit(ctx); err != nil {
			return nil, apierr.FromPg(err, apierr.PgContext{Resource: d.Name})
		}
		return nil, &RawResponse{x}
	case nil:
	case map[string]any:
		if item, err = rowFromScript(d, x, "/result"); err != nil {
			return nil, err
		}
	default:
		return nil, &apierr.Error{Code: apierr.InvalidQueryResult, Resource: d.Name, Pointer: "/command", Message: fmt.Sprintf("%s returned %s", d.Command, describe(v)), Hint: "An operation command returns nothing (204) or one row object with every property (rendered as the response)."}
	}
	if err := tx.Commit(ctx); err != nil {
		return nil, apierr.FromPg(err, apierr.PgContext{Resource: d.Name})
	}
	return item, nil
}
