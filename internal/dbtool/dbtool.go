// Package dbtool backs `caddy resource introspect` and `caddy resource sql`:
// it reads the schema of the database and runs one statement in a
// transaction, both printed compactly for coding agents.
package dbtool

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"sort"
	"strings"

	"github.com/jackc/pgx/v5"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

type Querier interface {
	Query(ctx context.Context, sql string, args ...any) (pgx.Rows, error)
}

type Reference struct {
	Table  string `json:"table"`
	Column string `json:"column"`
}

type Column struct {
	Name       string     `json:"name"`
	Type       string     `json:"type"`
	Nullable   bool       `json:"nullable"`
	Default    *string    `json:"default"`
	PrimaryKey bool       `json:"primaryKey"`
	References *Reference `json:"references,omitempty"`
}

type Table struct {
	Name    string   `json:"name"`
	Columns []Column `json:"columns"`
	Rows    *Rows    `json:"rows,omitempty"`
}

type Schema struct {
	Tables []Table `json:"tables"`
}

const columnsQuery = `
SELECT c.relname, a.attname, format_type(a.atttypid, a.atttypmod), NOT a.attnotnull, pg_get_expr(d.adbin, d.adrelid)
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
LEFT JOIN pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p')
ORDER BY c.relname, a.attnum`

const keysQuery = `
SELECT c.relname, a.attname
FROM pg_index i
JOIN pg_class c ON c.oid = i.indrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN LATERAL unnest(i.indkey::int2[]) WITH ORDINALITY AS k(attnum, pos) ON true
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = k.attnum
WHERE i.indisprimary AND n.nspname = current_schema()
ORDER BY c.relname, k.pos`

const referencesQuery = `
SELECT c.relname, a.attname, fc.relname, fa.attname
FROM pg_constraint con
JOIN pg_class c ON c.oid = con.conrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN LATERAL unnest(con.conkey, con.confkey) AS k(attnum, fattnum) ON true
JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
JOIN pg_class fc ON fc.oid = con.confrelid
JOIN pg_attribute fa ON fa.attrelid = con.confrelid AND fa.attnum = k.fattnum
WHERE con.contype = 'f' AND n.nspname = current_schema()`

func dbErr(err error, query string) error {
	return apierr.FromPg(err, apierr.PgContext{SQL: query})
}

// Introspect describes the tables of the current schema (only table when
// non-empty) and reads their first rows first rows ordered by primary key.
func Introspect(ctx context.Context, db Querier, table string, rows int) (*Schema, error) {
	var tables []Table
	index := map[string]int{}
	err := scan(ctx, db, columnsQuery, func(r pgx.Rows) error {
		var t string
		var c Column
		if err := r.Scan(&t, &c.Name, &c.Type, &c.Nullable, &c.Default); err != nil {
			return err
		}
		i, ok := index[t]
		if !ok {
			i = len(tables)
			index[t] = i
			tables = append(tables, Table{Name: t})
		}
		tables[i].Columns = append(tables[i].Columns, c)
		return nil
	})
	if err != nil {
		return nil, err
	}
	if table != "" {
		i, ok := index[table]
		if !ok {
			return nil, unknownTable(table, tables)
		}
		tables = []Table{tables[i]}
		index = map[string]int{table: 0}
	}
	keys := map[string][]string{}
	err = scan(ctx, db, keysQuery, func(r pgx.Rows) error {
		var t, col string
		if err := r.Scan(&t, &col); err != nil {
			return err
		}
		keys[t] = append(keys[t], col)
		return nil
	})
	if err != nil {
		return nil, err
	}
	err = scan(ctx, db, referencesQuery, func(r pgx.Rows) error {
		var t, col, ft, fcol string
		if err := r.Scan(&t, &col, &ft, &fcol); err != nil {
			return err
		}
		i, ok := index[t]
		if !ok {
			return nil
		}
		for j := range tables[i].Columns {
			if tables[i].Columns[j].Name == col {
				tables[i].Columns[j].References = &Reference{Table: ft, Column: fcol}
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	for i := range tables {
		pk := keys[tables[i].Name]
		for j := range tables[i].Columns {
			c := &tables[i].Columns[j]
			for _, k := range pk {
				if k == c.Name {
					c.PrimaryKey = true
				}
			}
		}
		if rows <= 0 {
			continue
		}
		data, err := sample(ctx, db, tables[i].Name, pk, rows)
		if err != nil {
			return nil, err
		}
		tables[i].Rows = data
	}
	return &Schema{Tables: tables}, nil
}

func scan(ctx context.Context, db Querier, query string, each func(pgx.Rows) error) error {
	rows, err := db.Query(ctx, query)
	if err != nil {
		return dbErr(err, query)
	}
	defer rows.Close()
	for rows.Next() {
		if err := each(rows); err != nil {
			return dbErr(err, query)
		}
	}
	if err := rows.Err(); err != nil {
		return dbErr(err, query)
	}
	return nil
}

func sample(ctx context.Context, db Querier, table string, pk []string, limit int) (*Rows, error) {
	query := "SELECT * FROM " + sqlf.TableIdent(table)
	if len(pk) > 0 {
		idents := make([]string, len(pk))
		for i, c := range pk {
			idents[i] = sqlf.Ident(c)
		}
		query += " ORDER BY " + strings.Join(idents, ", ")
	}
	query += fmt.Sprintf(" LIMIT %d", limit)
	res, err := collect(ctx, db, query, nil)
	if err != nil {
		return nil, err
	}
	return &res.Rows, nil
}

func unknownTable(name string, tables []Table) error {
	names := make([]string, len(tables))
	for i, t := range tables {
		names[i] = t.Name
	}
	sort.Strings(names)
	hint := "Tables: " + strings.Join(names, ", ") + "."
	if len(names) == 0 {
		hint = "The current schema has no table."
	}
	if s := apierr.Suggest(name, names); s != "" {
		hint = fmt.Sprintf("Did you mean %q? ", s) + hint
	}
	return &apierr.Error{Code: apierr.UsageError, Message: fmt.Sprintf("introspect: no table %q in the current schema", name), Hint: hint}
}

// WriteText prints one block per table: one line per column, then the
// sampled rows as an aligned table.
func (s *Schema) WriteText(w io.Writer) {
	for _, t := range s.Tables {
		fmt.Fprintln(w, t.Name)
		for _, c := range t.Columns {
			line := "  " + c.Name + " " + c.Type
			switch {
			case c.PrimaryKey:
				line += " pk"
			case !c.Nullable:
				line += " not null"
			}
			if c.Default != nil {
				line += " default " + *c.Default
			}
			if c.References != nil {
				line += " -> " + c.References.Table + "." + c.References.Column
			}
			fmt.Fprintln(w, line)
		}
		if t.Rows != nil {
			fmt.Fprintln(w, "  rows:")
			if len(t.Rows.Data) == 0 {
				fmt.Fprintln(w, "   (none)")
				continue
			}
			t.Rows.writeTable(w, "   ")
		}
	}
}

func (s *Schema) WriteJSON(w io.Writer) error {
	enc := json.NewEncoder(w)
	enc.SetIndent("", "  ")
	return enc.Encode(s)
}

// Result is the outcome of one statement: rows for a statement returning
// some, the number of rows affected otherwise (also set for RETURNING).
type Result struct {
	Rows      Rows
	IsQuery   bool
	Affected  int64
	Committed bool
}

func collect(ctx context.Context, q Querier, query string, args []any) (*Result, error) {
	rows, err := q.Query(ctx, query, args...)
	if err != nil {
		return nil, dbErr(err, query)
	}
	defer rows.Close()
	res := &Result{}
	fields := rows.FieldDescriptions()
	for _, f := range fields {
		res.Rows.Columns = append(res.Rows.Columns, f.Name)
	}
	res.IsQuery = len(fields) > 0
	for rows.Next() {
		vals, err := rows.Values()
		if err != nil {
			return nil, dbErr(err, query)
		}
		res.Rows.Data = append(res.Rows.Data, vals)
	}
	if err := rows.Err(); err != nil {
		return nil, dbErr(err, query)
	}
	rows.Close()
	res.Affected = rows.CommandTag().RowsAffected()
	return res, nil
}

type Beginner interface {
	Begin(ctx context.Context) (pgx.Tx, error)
}

// RunSQL runs one statement with positional arguments (text, converted by
// PostgreSQL to the parameter types) in a transaction rolled back unless
// commit is set.
func RunSQL(ctx context.Context, db Beginner, query string, args []string, commit bool) (*Result, error) {
	tx, err := db.Begin(ctx)
	if err != nil {
		return nil, dbErr(err, "")
	}
	defer func() { _ = tx.Rollback(context.Background()) }()
	params := make([]any, len(args))
	for i, a := range args {
		params[i] = a
	}
	res, err := collect(ctx, tx, query, params)
	if err != nil {
		return nil, err
	}
	if commit {
		if err := tx.Commit(ctx); err != nil {
			return nil, dbErr(err, query)
		}
		res.Committed = true
	}
	return res, nil
}

func (r *Result) WriteText(w io.Writer) {
	if !r.IsQuery {
		fmt.Fprintf(w, "%d rows affected\n", r.Affected)
		return
	}
	if len(r.Rows.Data) == 0 {
		fmt.Fprintln(w, strings.Join(r.Rows.Columns, " | "))
		fmt.Fprintln(w, "(0 rows)")
		return
	}
	r.Rows.writeTable(w, "")
}

func (r *Result) WriteJSON(w io.Writer) error {
	enc := json.NewEncoder(w)
	enc.SetIndent("", "  ")
	if !r.IsQuery {
		return enc.Encode(map[string]int64{"rowsAffected": r.Affected})
	}
	return enc.Encode(r.Rows)
}
