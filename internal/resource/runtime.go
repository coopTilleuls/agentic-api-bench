package resource

import (
	"context"
	"errors"
	"fmt"
	"math"
	"math/big"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

type Item map[string]any

// RawResponse is returned as the error of a read or write whose script
// answered with ctx.response: the HTTP layer sends it as is instead of
// serializing a row. It is only produced for dedicated operations.
type RawResponse struct{ *script.Response }

func (r *RawResponse) Error() string {
	return fmt.Sprintf("raw response %d (%d bytes)", r.Status, len(r.Body))
}

type Runtime struct {
	Pool *pgxpool.Pool
	// Tx, when set, carries every statement of the runtime instead of Pool
	// (`caddy resource request` runs a whole request in a transaction).
	Tx     pgx.Tx
	Client *http.Client
	// Getenv reads environment variables for ctx.env; os.Getenv when nil.
	Getenv func(string) string
	// Set backs ctx.resource(); helpers are unavailable when nil.
	Set *Set
}

type database interface {
	querier
	QueryRow(ctx context.Context, sql string, args ...any) pgx.Row
	Begin(ctx context.Context) (pgx.Tx, error)
}

func (r *Runtime) db() database {
	if r.Tx != nil {
		return &stmtTx{Tx: r.Tx}
	}
	return r.Pool
}

func (r *Runtime) pgError(err error, d *Definition, pointer string, q sqlf.Query) error {
	if _, ok := apierr.As(err); ok {
		return err
	}
	return apierr.FromPg(err, apierr.PgContext{Resource: d.Name, Pointer: pointer, SQL: q.SQL, SourceOffset: q.SourceOffset})
}

func (r *Runtime) call(d *Definition, req script.Request, op, phase string, ex *Exec, pointer string, q querier) script.Call {
	if ex == nil {
		ex = &Exec{Page: 1, ItemsPerPage: d.ItemsPerPage, Filters: map[string]any{}, Values: map[string]any{}}
	}
	if q == nil {
		q = r.db()
	}
	raw := d.Current != nil && d.Current.Reads() && phase == "read"
	return script.Call{Request: req, Host: r.host(q, d, pointer), Operation: op, Phase: phase, Page: ex.Page, ItemsPerPage: ex.ItemsPerPage, Filters: ex.Filters, Values: ex.Values, Resources: r.resources(), RawAllowed: raw}
}

// tableSource reads the declared properties of a table resource, each
// column aliased to its property.
func tableSource(d *Definition) sqlf.Fragment {
	cols := make([]string, 0, len(d.Properties))
	for _, p := range d.Properties {
		c := sqlf.Ident(p.ColumnName())
		if p.ColumnName() != p.Name {
			c += " AS " + sqlf.Ident(p.Name)
		}
		cols = append(cols, c)
	}
	return sqlf.Raw("SELECT " + strings.Join(cols, ", ") + " FROM " + sqlf.TableIdent(d.Table))
}

// source runs the query function (or uses the table) and returns either a
// SQL fragment to compose, or the rows the function computed itself.
func (r *Runtime) source(ctx context.Context, d *Definition, call script.Call) (sqlf.Fragment, any, bool, string, error) {
	if d.QueryMod == nil {
		return tableSource(d), nil, true, "/table", nil
	}
	v, err := d.QueryMod.Call(ctx, "default", call)
	if err != nil {
		return sqlf.Fragment{}, nil, false, "/query", tag(err, d, "/query")
	}
	if f, ok := v.(sqlf.Fragment); ok {
		return f, nil, true, "/query", nil
	}
	if resp, ok := v.(*script.Response); ok {
		return sqlf.Fragment{}, nil, false, "/query", &RawResponse{resp}
	}
	return sqlf.Fragment{}, v, false, "/query", nil
}

func (r *Runtime) List(ctx context.Context, d *Definition, req script.Request, ex *Exec) ([]Item, int64, error) {
	call := r.call(d, req, "collection", "read", ex, "/query", nil)
	src, v, isSQL, pointer, err := r.source(ctx, d, call)
	if err != nil {
		return nil, 0, err
	}
	if !isSQL {
		m, ok := v.(map[string]any)
		if !ok {
			return nil, 0, invalidQueryResult(d, "collection", v)
		}
		return rowsResult(d, m)
	}
	countQ := sqlf.Count(src, ex.Where)
	var total int64
	if err := r.db().QueryRow(ctx, countQ.SQL, countQ.Args...).Scan(&total); err != nil {
		return nil, 0, r.pgError(err, d, pointer, countQ)
	}
	q := sqlf.Collection(src, sqlf.Select{Where: ex.Where, Order: ex.Order, Limit: ex.ItemsPerPage, Offset: (ex.Page - 1) * ex.ItemsPerPage})
	items, err := r.fetch(ctx, d, q)
	if err != nil {
		return nil, 0, r.pgError(err, d, pointer, q)
	}
	return items, total, nil
}

// Get reads one item. phase is "read", or "lookup" for the existence check
// of a write, so a query can see rows for writes that it hides for reads.
func (r *Runtime) Get(ctx context.Context, d *Definition, req script.Request, id any, phase string, ex *Exec) (Item, bool, error) {
	call := r.call(d, req, "item", phase, ex, "/query", nil)
	call.ID = id
	src, v, isSQL, pointer, err := r.source(ctx, d, call)
	if err != nil {
		return nil, false, err
	}
	if !isSQL {
		switch x := v.(type) {
		case nil:
			return nil, false, nil
		case map[string]any:
			item, err := rowFromScript(d, x, "/item")
			return item, err == nil, err
		default:
			return nil, false, invalidQueryResult(d, "item", v)
		}
	}
	var where []sqlf.Fragment
	if ex != nil {
		where = ex.Where
	}
	q := sqlf.Item(src, d.Identifier().Name, id, where)
	items, err := r.fetch(ctx, d, q)
	if err != nil {
		return nil, false, r.pgError(err, d, pointer, q)
	}
	if len(items) == 0 {
		return nil, false, nil
	}
	return items[0], true, nil
}

func tag(err error, d *Definition, pointer string) error {
	if e, ok := apierr.As(err); ok {
		if e.Resource == "" {
			e.Resource = d.Name
		}
		if e.Pointer == "" {
			e.Pointer = pointer
		}
		return e
	}
	return &apierr.Error{Code: apierr.InternalError, Resource: d.Name, Pointer: pointer, Message: err.Error()}
}

func invalidQueryResult(d *Definition, op string, v any) *apierr.Error {
	return &apierr.Error{Code: apierr.InvalidQueryResult, Resource: d.Name, Pointer: "/query", Message: fmt.Sprintf("%s returned %s for a %s request", d.Query, describe(v), op),
		Hint: "Return a ctx.sql`SELECT ...` fragment (the server paginates, filters and looks items up), or compute rows yourself: for ctx.operation === \"collection\" return {items: [...], totalItems: n} honoring ctx.page, ctx.itemsPerPage and ctx.filters; for \"item\" return the row whose identifier equals ctx.id, or null."}
}

func describe(v any) string {
	switch x := v.(type) {
	case nil:
		return "null"
	case []any:
		return fmt.Sprintf("an array of %d elements", len(x))
	case string:
		return fmt.Sprintf("the string %q", truncate(x, 60))
	case float64, int64:
		return fmt.Sprintf("the number %v", x)
	case bool:
		return fmt.Sprintf("the boolean %v", x)
	case map[string]any:
		return "an object"
	}
	return fmt.Sprintf("a %T", v)
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "..."
}

func rowsResult(d *Definition, m map[string]any) ([]Item, int64, error) {
	rawItems, ok := m["items"].([]any)
	if !ok {
		return nil, 0, &apierr.Error{Code: apierr.InvalidRow, Resource: d.Name, Pointer: "/query", Message: "the collection result has no \"items\" array", Hint: "Return {items: [...rows], totalItems: <number of rows across all pages>}."}
	}
	total, ok := m["totalItems"].(int64)
	if t, snake := m["total_items"].(int64); !ok && snake {
		total, ok = t, true
	}
	if !ok {
		return nil, 0, &apierr.Error{Code: apierr.InvalidRow, Resource: d.Name, Pointer: "/query", Message: "the collection result has no integer \"totalItems\"", Hint: "Return {items: [...rows], totalItems: <number of rows across all pages>} (total_items is accepted too); it drives pagination links."}
	}
	items := make([]Item, 0, len(rawItems))
	for i, raw := range rawItems {
		row, ok := raw.(map[string]any)
		if !ok {
			return nil, 0, &apierr.Error{Code: apierr.InvalidRow, Resource: d.Name, Pointer: "/query", Message: fmt.Sprintf("items[%d] is %s, not an object", i, describe(raw)), Hint: "Each item is an object with one key per property."}
		}
		item, err := rowFromScript(d, row, fmt.Sprintf("/items/%d", i))
		if err != nil {
			return nil, 0, err
		}
		items = append(items, item)
	}
	return items, total, nil
}

func rowFromScript(d *Definition, row map[string]any, at string) (Item, error) {
	props := d.Output()
	item := make(Item, len(props))
	for _, p := range props {
		v, ok := row[p.Name]
		if !ok {
			keys := make([]string, 0, len(row))
			for k := range row {
				keys = append(keys, k)
			}
			sort.Strings(keys)
			return nil, &apierr.Error{Code: apierr.InvalidRow, Resource: d.Name, Pointer: "/query", Message: fmt.Sprintf("%s has no %q key (property %s)", at, p.Name, p.Name), Hint: fmt.Sprintf("Each row needs every declared property. The row has: %s. Map the source fields to the property names in the script.", strings.Join(keys, ", "))}
		}
		cv, err := coerce(p, v)
		if err != nil {
			return nil, &apierr.Error{Code: apierr.InvalidRow, Resource: d.Name, Pointer: "/query", Message: fmt.Sprintf("%s/%s is %s, not a %s", at, p.Name, describe(v), p.Type), Hint: "Convert the value in the script, e.g. Number(x), String(x), new Date(x).toISOString()."}
		}
		item[p.Name] = outputValue(p, cv)
	}
	if id, ok := d.HiddenIdentifier(); ok {
		if v, ok := row[id.Name]; ok {
			if cv, err := coerce(id, v); err == nil {
				item[id.Name] = outputValue(id, cv)
			}
		}
	}
	return item, nil
}

// coerce checks a JSON value against a property type. Datetimes come back as
// time.Time so they can be bound as SQL parameters.
func coerce(p Property, v any) (any, error) {
	if v == nil {
		return nil, nil
	}
	switch p.Type {
	case "string":
		if s, ok := v.(string); ok {
			return s, nil
		}
	case "integer":
		switch x := v.(type) {
		case int64:
			return x, nil
		case float64:
			if x == float64(int64(x)) {
				return int64(x), nil
			}
		}
	case "number":
		switch x := v.(type) {
		case int64:
			return float64(x), nil
		case float64:
			return x, nil
		}
	case "boolean":
		if b, ok := v.(bool); ok {
			return b, nil
		}
	case "datetime":
		if s, ok := v.(string); ok {
			if t, err := ParseValue("datetime", s); err == nil {
				return t, nil
			}
		}
		if t, ok := v.(time.Time); ok {
			return t, nil
		}
	case "date":
		if s, ok := v.(string); ok && isDate(s) {
			return s, nil
		}
		if t, ok := v.(time.Time); ok {
			return t, nil
		}
	case "object":
		switch v.(type) {
		case map[string]any, Object:
			return v, nil
		}
	case "array":
		if _, ok := v.([]any); ok {
			return v, nil
		}
	}
	return nil, errInvalid
}

func (r *Runtime) fetch(ctx context.Context, d *Definition, q sqlf.Query) ([]Item, error) {
	var items []Item
	err := r.each(ctx, d, q, func(it Item) error {
		items = append(items, it)
		return nil
	})
	return items, err
}

func (r *Runtime) each(ctx context.Context, d *Definition, q sqlf.Query, yield func(Item) error) error {
	rows, err := r.db().Query(ctx, q.SQL, q.Args...)
	if err != nil {
		return err
	}
	defer rows.Close()
	fields := rows.FieldDescriptions()
	for rows.Next() {
		vals, err := rows.Values()
		if err != nil {
			return err
		}
		byCol := make(map[string]any, len(vals))
		for i, f := range fields {
			byCol[f.Name] = vals[i]
		}
		item, err := mapRow(d, byCol)
		if err != nil {
			return err
		}
		if err := yield(item); err != nil {
			return err
		}
	}
	return rows.Err()
}

// Stream yields every row matching the parameters without buffering SQL
// results: it backs unpaginated exports. Nothing is yielded before the
// first row is fetched, so query errors surface before any output.
func (r *Runtime) Stream(ctx context.Context, d *Definition, req script.Request, ex *Exec, yield func(Item) error) error {
	call := r.call(d, req, "collection", "read", ex, "/query", nil)
	call.Page, call.ItemsPerPage = 1, math.MaxInt32
	src, v, isSQL, pointer, err := r.source(ctx, d, call)
	if err != nil {
		return err
	}
	if !isSQL {
		m, ok := v.(map[string]any)
		if !ok {
			return invalidQueryResult(d, "collection", v)
		}
		items, _, err := rowsResult(d, m)
		if err != nil {
			return err
		}
		for _, it := range items {
			if err := yield(it); err != nil {
				return err
			}
		}
		return nil
	}
	q := sqlf.All(src, sqlf.Select{Where: ex.Where, Order: ex.Order})
	if err := r.each(ctx, d, q, yield); err != nil {
		return r.pgError(err, d, pointer, q)
	}
	return nil
}

func toJSON(typ string, v any) any {
	if v == nil {
		return nil
	}
	switch x := v.(type) {
	case pgtype.Numeric:
		if typ == "integer" {
			if i, err := x.Int64Value(); err == nil {
				return i.Int64
			}
		}
		f, err := x.Float64Value()
		if err != nil || !f.Valid {
			return nil
		}
		return f.Float64
	case int16:
		return numberOrInt(typ, int64(x))
	case int32:
		return numberOrInt(typ, int64(x))
	case int64:
		return numberOrInt(typ, x)
	case float32:
		return float64(x)
	case time.Time:
		return x.UTC().Format(time.RFC3339Nano)
	case [16]byte:
		return uuid.UUID(x).String()
	case *big.Int:
		return x.String()
	}
	if typ == "string" {
		if s, ok := v.(string); ok {
			return s
		}
		return fmt.Sprint(v)
	}
	return v
}

func numberOrInt(typ string, i int64) any {
	if typ == "number" {
		return float64(i)
	}
	return i
}

var errInvalid = errors.New("invalid")

func ParseValue(typ, s string) (any, error) {
	switch typ {
	case "integer":
		i, err := strconv.ParseInt(s, 10, 64)
		if err != nil {
			return nil, errInvalid
		}
		return i, nil
	case "number":
		f, err := strconv.ParseFloat(s, 64)
		if err != nil {
			return nil, errInvalid
		}
		return f, nil
	case "boolean":
		switch s {
		case "true", "1":
			return true, nil
		case "false", "0":
			return false, nil
		}
		return nil, errInvalid
	case "datetime":
		for _, layout := range []string{time.RFC3339Nano, "2006-01-02"} {
			if t, err := time.Parse(layout, s); err == nil {
				return t, nil
			}
		}
		return nil, errInvalid
	}
	return s, nil
}

// TableHas reports whether the table of d holds a row with this identifier,
// whatever the query shows.
func (r *Runtime) TableHas(ctx context.Context, d *Definition, id any) (bool, error) {
	var exists bool
	sql := "SELECT EXISTS (SELECT 1 FROM " + sqlf.TableIdent(d.Table) + " WHERE " + sqlf.Ident(d.Identifier().ColumnName()) + " = $1)"
	err := r.db().QueryRow(ctx, sql, id).Scan(&exists)
	return exists, err
}
