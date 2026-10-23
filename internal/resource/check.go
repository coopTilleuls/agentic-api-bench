package resource

import (
	"context"
	"fmt"
	"net/http"
	"slices"
	"strings"

	"github.com/jackc/pgx/v5/pgtype"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

type column struct {
	name string
	oid  uint32
}

// Check validates every definition against the database: the source relation
// must run, return each declared property as a column, with a compatible type.
func Check(ctx context.Context, rt *Runtime, set *Set) apierr.List {
	if err := rt.Pool.Ping(ctx); err != nil {
		return apierr.List{apierr.FromPg(err, apierr.PgContext{})}
	}
	var errs apierr.List
	for _, d := range set.Defs {
		if len(d.Ops) == 0 {
			errs = append(errs, checkDefinition(ctx, rt, d)...)
			continue
		}
		for i, op := range d.Ops {
			if !op.Reads() || (i > 0 && d.Ops[i-1].Index == op.Index) {
				continue
			}
			for _, e := range checkDefinition(ctx, rt, d.ForOperation(op)) {
				if op.Query != "" && e.Pointer == "/query" {
					e.Pointer = fmt.Sprintf("/operations/%d/query", op.Index)
				}
				errs = append(errs, e)
			}
		}
	}
	return errs
}

func probeRequest(d *Definition) script.Request {
	return script.Request{Method: http.MethodGet, Path: d.Path, Header: http.Header{}}
}

func checkDefinition(ctx context.Context, rt *Runtime, d *Definition) apierr.List {
	pool := rt.Pool
	var src sqlf.Fragment
	pointer := "/query"
	if d.QueryMod == nil {
		pointer = "/table"
		var exists bool
		if err := pool.QueryRow(ctx, "SELECT to_regclass($1) IS NOT NULL", sqlf.TableIdent(d.Table)).Scan(&exists); err != nil {
			return apierr.List{apierr.FromPg(err, apierr.PgContext{Resource: d.Name, Pointer: pointer})}
		}
		if !exists {
			return apierr.List{{Code: apierr.UnknownTable, Resource: d.Name, Pointer: pointer, Message: fmt.Sprintf("table %q does not exist", d.Table), Hint: tablesHint(ctx, pool, d.Table)}}
		}
		src = sqlf.Raw("SELECT * FROM " + sqlf.TableIdent(d.Table))
	} else {
		v, err := d.QueryMod.Call(ctx, "default", script.Call{Request: probeRequest(d), Host: probeHost{rt.host(pool, d, pointer)}, Operation: "collection", Phase: "read", Validating: true, Page: 1, ItemsPerPage: d.ItemsPerPage, Filters: map[string]any{}, Values: map[string]any{}, Resources: rt.resources(), RawAllowed: d.Current != nil})
		if e, ok := apierr.As(err); ok && (e == errProbeIO || e.Rejected) {
			return nil
		}
		if err != nil {
			e, _ := apierr.As(tag(err, d, pointer))
			return apierr.List{e}
		}
		switch x := v.(type) {
		case sqlf.Fragment:
			src = x
		case *script.Response:
			return nil
		case map[string]any:
			if _, _, err := rowsResult(d, x); err != nil {
				e, _ := apierr.As(err)
				return apierr.List{e}
			}
			return nil
		default:
			return apierr.List{invalidQueryResult(d, "collection", v)}
		}
	}

	q := sqlf.Probe(src)
	rows, err := pool.Query(ctx, q.SQL, q.Args...)
	if err == nil {
		rows.Close()
		err = rows.Err()
	}
	if err != nil {
		e := apierr.FromPg(err, apierr.PgContext{Resource: d.Name, Pointer: pointer, SQL: q.SQL, SourceOffset: q.SourceOffset})
		if e.Code == apierr.UnknownTable {
			e.Hint = tablesHint(ctx, pool, "")
		}
		return apierr.List{e}
	}
	var cols []column
	for _, fd := range rows.FieldDescriptions() {
		cols = append(cols, column{name: fd.Name, oid: fd.DataTypeOID})
	}
	return checkColumns(ctx, pool, d, cols)
}

func checkColumns(ctx context.Context, pool *pgxpool.Pool, d *Definition, cols []column) apierr.List {
	var errs apierr.List
	names := make([]string, len(cols))
	byName := map[string]column{}
	var dups []string
	for i, c := range cols {
		names[i] = c.name
		if _, ok := byName[c.name]; ok && !slices.Contains(dups, c.name) {
			dups = append(dups, c.name)
		}
		byName[c.name] = c
	}
	for _, dup := range dups {
		errs = append(errs, &apierr.Error{Code: apierr.DuplicateColumn, Resource: d.Name, Pointer: "/query", Message: fmt.Sprintf("the query returns column %q more than once", dup), Hint: fmt.Sprintf("Every returned column needs a unique name. Alias the extra ones, e.g. a.%s AS author_%s. Query returns: %s.", dup, dup, strings.Join(names, ", "))})
	}
	if len(dups) > 0 {
		return errs
	}

	if d.Table != "" && d.CommandMod == nil && d.Input != nil {
		tableCols := byName
		if d.QueryMod != nil {
			tableCols = tableColumns(ctx, pool, d.Table)
		}
		for _, in := range d.Input {
			col := d.InputColumn(in)
			if _, ok := tableCols[col]; !ok && tableCols != nil {
				errs = append(errs, &apierr.Error{Code: apierr.UnknownColumn, Resource: d.Name, Pointer: "/input/" + in.Name, Message: fmt.Sprintf("input %q is written to column %q, which table %q does not have", in.Name, col, d.Table), Hint: fmt.Sprintf("Generated INSERT/UPDATE write each input to its \"column\" (default: the column of the property of the same name, else the input name). Table %s has columns: %s. Set \"column\", or add a \"command\" to write it yourself.", d.Table, strings.Join(sortedKeys(tableCols), ", "))})
			}
		}
	}

	for _, p := range d.Output() {
		ptr := "/properties/" + p.Name
		colName := p.Name
		if d.QueryMod == nil {
			colName = p.ColumnName()
		}
		c, ok := byName[colName]
		if !ok && p.Type == "object" && len(p.Properties) > 0 && d.QueryMod != nil {
			for _, sub := range p.Properties {
				sc, ok := byName[p.Name+"_"+sub.Name]
				sptr := ptr + "/properties/" + sub.Name
				if !ok {
					errs = append(errs, &apierr.Error{Code: apierr.UnknownColumn, Resource: d.Name, Pointer: sptr, Message: fmt.Sprintf("column %q (nested property %s.%s) is not returned by the query", p.Name+"_"+sub.Name, p.Name, sub.Name), Hint: fmt.Sprintf("Return a column named %s: object property %s is filled from a json column %q, or from one column per nested property named %s_<nested>. Query returns: %s. With a relation, select ${ctx.resource(%q).relation(%q).columns()}.", p.Name+"_"+sub.Name, p.Name, p.Name, p.Name, strings.Join(names, ", "), d.Name, p.Name)})
					continue
				}
				if !compatible(sub.Type, sc.oid) {
					errs = append(errs, mismatch(ctx, pool, d, sub, sc, sptr))
				}
			}
			continue
		}
		if !ok {
			errs = append(errs, &apierr.Error{Code: apierr.UnknownColumn, Resource: d.Name, Pointer: ptr, Message: missingColumnMessage(d, colName), Hint: missingColumnHint(d, colName, names)})
			continue
		}
		if !compatible(p.Type, c.oid) {
			errs = append(errs, mismatch(ctx, pool, d, p, c, ptr))
		}
	}
	return errs
}

func sortedKeys(m map[string]column) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	slices.Sort(out)
	return out
}

func tableColumns(ctx context.Context, pool *pgxpool.Pool, table string) map[string]column {
	rows, err := pool.Query(ctx, "SELECT * FROM "+sqlf.TableIdent(table)+" LIMIT 0")
	if err != nil {
		return nil
	}
	rows.Close()
	out := map[string]column{}
	for _, fd := range rows.FieldDescriptions() {
		out[fd.Name] = column{name: fd.Name, oid: fd.DataTypeOID}
	}
	return out
}

func mismatch(ctx context.Context, pool *pgxpool.Pool, d *Definition, p Property, c column, ptr string) *apierr.Error {
	pgType := typeName(ctx, pool, c.oid)
	hint := fmt.Sprintf("Column %q is %s.", c.name, pgType)
	if p.Type == "object" || p.Type == "array" {
		hint += fmt.Sprintf(" %s properties are filled from json or jsonb columns: json_build_object(...), json_agg(...) or to_json(...).", p.Type)
		return &apierr.Error{Code: apierr.TypeMismatch, Resource: d.Name, Pointer: ptr + "/type", Message: fmt.Sprintf("property %q is declared %q but column %q is %s", p.Name, p.Type, c.name, pgType), Hint: hint}
	}
	if s := suggestType(c.oid); s != "" {
		hint += fmt.Sprintf(" Declare the property as \"type\": %q", s)
		if d.Query != "" {
			hint += fmt.Sprintf(", or cast it in the query (%s::%s AS %s)", c.name, castFor(p.Type), c.name)
		}
		hint += "."
	} else if d.Query != "" {
		hint += fmt.Sprintf(" Cast it in the query, e.g. %s::%s AS %s.", c.name, castFor(p.Type), c.name)
	}
	return &apierr.Error{Code: apierr.TypeMismatch, Resource: d.Name, Pointer: ptr + "/type", Message: fmt.Sprintf("property %q is declared %q but column %q is %s", p.Name, p.Type, c.name, pgType), Hint: hint}
}

func missingColumnMessage(d *Definition, name string) string {
	if d.Table != "" {
		return fmt.Sprintf("column %q does not exist in table %q", name, d.Table)
	}
	return fmt.Sprintf("column %q is not returned by the query", name)
}

func missingColumnHint(d *Definition, name string, available []string) string {
	var hint string
	if d.Table != "" {
		hint = fmt.Sprintf("Table %s has columns: %s.", d.Table, strings.Join(available, ", "))
	} else {
		hint = fmt.Sprintf("Query returns: %s.", strings.Join(available, ", "))
	}
	for _, a := range available {
		if a != name && strings.EqualFold(a, name) {
			return hint + fmt.Sprintf(" PostgreSQL folds unquoted identifiers to lower case: write AS %q (double quotes) in the query, or name the property %q.", name, a)
		}
	}
	if s := apierr.Suggest(name, available); s != "" {
		hint += fmt.Sprintf(" Did you mean %q?", s)
	}
	if d.Table != "" {
		return hint + " Rename the property to an existing column. To expose a renamed, computed or joined value, replace \"table\" with a \"query\" file."
	}
	return hint + fmt.Sprintf(" Alias the column in the SELECT (expr AS %s) or rename the property.", name)
}

func tablesHint(ctx context.Context, pool *pgxpool.Pool, wanted string) string {
	rows, err := pool.Query(ctx, `SELECT CASE WHEN table_schema = ANY(current_schemas(false)) THEN table_name ELSE table_schema || '.' || table_name END
		FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY 1`)
	if err != nil {
		return "Check the table name."
	}
	defer rows.Close()
	var tables []string
	for rows.Next() {
		var t string
		if rows.Scan(&t) == nil {
			tables = append(tables, t)
		}
	}
	if len(tables) == 0 {
		return "The database has no table the dsn user can see."
	}
	hint := "Existing tables: " + strings.Join(tables, ", ") + "."
	if wanted != "" {
		if s := apierr.Suggest(wanted, tables); s != "" {
			hint = fmt.Sprintf("Did you mean %q? ", s) + hint
		}
	}
	return hint
}

var unknownTypeMap = pgtype.NewMap()

func typeName(ctx context.Context, pool *pgxpool.Pool, oid uint32) string {
	var name string
	if err := pool.QueryRow(ctx, "SELECT format_type($1::oid, NULL)", oid).Scan(&name); err == nil {
		return name
	}
	if t, ok := unknownTypeMap.TypeForOID(oid); ok {
		return t.Name
	}
	return fmt.Sprintf("oid %d", oid)
}

func isInt(oid uint32) bool {
	return oid == pgtype.Int2OID || oid == pgtype.Int4OID || oid == pgtype.Int8OID
}

func isNumber(oid uint32) bool {
	return isInt(oid) || oid == pgtype.Float4OID || oid == pgtype.Float8OID || oid == pgtype.NumericOID
}

func isDatetime(oid uint32) bool {
	return oid == pgtype.DateOID || oid == pgtype.TimestampOID || oid == pgtype.TimestamptzOID
}

func isText(oid uint32) bool {
	switch oid {
	case pgtype.TextOID, pgtype.VarcharOID, pgtype.BPCharOID, pgtype.NameOID, pgtype.UUIDOID, pgtype.QCharOID:
		return true
	}
	return false
}

// builtinOID reports OIDs of core types that are known not to be text-like.
// Anything else (enums, domains, citext) has a database-specific OID and is
// accepted as a string.
func builtinOID(oid uint32) bool {
	_, ok := unknownTypeMap.TypeForOID(oid)
	return ok
}

func compatible(typ string, oid uint32) bool {
	switch typ {
	case "integer":
		return isInt(oid)
	case "number":
		return isNumber(oid)
	case "boolean":
		return oid == pgtype.BoolOID
	case "datetime":
		return isDatetime(oid)
	case "date":
		return oid == pgtype.DateOID
	case "object", "array":
		return oid == pgtype.JSONOID || oid == pgtype.JSONBOID
	case "string":
		return isText(oid) || !builtinOID(oid)
	}
	return false
}

func suggestType(oid uint32) string {
	switch {
	case isInt(oid):
		return "integer"
	case isNumber(oid):
		return "number"
	case oid == pgtype.BoolOID:
		return "boolean"
	case isDatetime(oid):
		return "datetime"
	case isText(oid):
		return "string"
	}
	return ""
}

func castFor(typ string) string {
	switch typ {
	case "integer":
		return "bigint"
	case "number":
		return "float8"
	case "boolean":
		return "boolean"
	case "datetime":
		return "timestamptz"
	case "date":
		return "date"
	case "object", "array":
		return "json"
	}
	return "text"
}

// errProbeIO stops a validation probe at its first I/O. A query that fetches
// or reads the database itself returns rows, not SQL: running it would call
// remote APIs on every reload, so its rows are only validated at runtime.
var errProbeIO = &apierr.Error{Code: apierr.ScriptError, Message: "validation probe stopped at the first I/O call"}

type probeHost struct{ script.Host }

func (probeHost) Query(context.Context, sqlf.Fragment) ([]map[string]any, error) {
	return nil, errProbeIO
}

func (probeHost) Exec(context.Context, sqlf.Fragment) (int64, error) {
	return 0, errProbeIO
}

func (probeHost) Fetch(context.Context, script.FetchRequest) (*script.FetchResponse, error) {
	return nil, errProbeIO
}
