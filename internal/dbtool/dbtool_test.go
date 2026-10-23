package dbtool

import (
	"bytes"
	"context"
	"encoding/json"
	"strings"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

func TestIntrospectText(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	schema, err := Introspect(context.Background(), pool, "", 2)
	if err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	schema.WriteText(&out)
	got := out.String()
	for _, want := range []string{
		"author\n  id integer pk default nextval(",
		"\n  name text not null\n",
		"\nbook\n",
		"\n  author_id integer -> author.id\n",
		"\n  tenant text not null default 'acme'::text\n",
		"\n  price numeric(6,2)\n",
		"\n  released_at timestamp with time zone\n",
		"\n  rows:\n   id | name\n   1  | Ursula K. Le Guin\n   2  | Frank Herbert\n",
	} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in:\n%s", want, got)
		}
	}
	if !strings.Contains(got, "A Wizard of Earthsea") || !strings.Contains(got, "The Left Hand of Darkness") {
		t.Errorf("book rows missing:\n%s", got)
	}
	if strings.Contains(got, "Dune") {
		t.Errorf("--rows 2 must stop after two book rows:\n%s", got)
	}
}

func TestIntrospectOneTableWithoutRows(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	schema, err := Introspect(context.Background(), pool, "book", 0)
	if err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	schema.WriteText(&out)
	if got := out.String(); strings.Contains(got, "author\n") || strings.Contains(got, "rows:") || !strings.HasPrefix(got, "book\n") {
		t.Fatalf("got:\n%s", got)
	}
}

func TestIntrospectUnknownTableListsTables(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	_, err := Introspect(context.Background(), pool, "boook", 0)
	e, ok := apierr.As(err)
	if !ok || e.Code != apierr.UsageError || !strings.Contains(e.Message, `"boook"`) || !strings.Contains(e.Hint, "Did you mean \"book\"") || !strings.Contains(e.Hint, "author, book") {
		t.Fatalf("got %v", err)
	}
}

func TestIntrospectTruncatesLongValuesAndShowsNulls(t *testing.T) {
	pool := testdb.Pool(t, `CREATE TABLE note (id int PRIMARY KEY, body text, extra jsonb); INSERT INTO note VALUES (1, repeat('x', 100), '{"a": [1, 2]}'), (2, E'two\nlines', NULL);`)
	schema, err := Introspect(context.Background(), pool, "note", 3)
	if err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	schema.WriteText(&out)
	got := out.String()
	if want := strings.Repeat("x", 59) + "…"; !strings.Contains(got, want+" ") && !strings.Contains(got, want+"\n") {
		t.Errorf("no truncated value in:\n%s", got)
	}
	for _, want := range []string{`two\nlines`, "NULL", `{"a":[1,2]}`} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in:\n%s", want, got)
		}
	}
}

func TestIntrospectCompositeKeysAndOrdering(t *testing.T) {
	pool := testdb.Pool(t, `CREATE TABLE line (order_id int, n int, qty int, PRIMARY KEY (order_id, n)); INSERT INTO line VALUES (2, 1, 5), (1, 2, 6), (1, 1, 7);`)
	schema, err := Introspect(context.Background(), pool, "line", 3)
	if err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	schema.WriteText(&out)
	got := out.String()
	if !strings.Contains(got, "  order_id integer pk\n  n integer pk\n  qty integer\n") || !strings.Contains(got, "   1        | 1 | 7\n   1        | 2 | 6\n   2        | 1 | 5\n") {
		t.Fatalf("got:\n%s", got)
	}
}

func TestIntrospectJSON(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	schema, err := Introspect(context.Background(), pool, "book", 1)
	if err != nil {
		t.Fatal(err)
	}
	b, err := json.Marshal(schema)
	if err != nil {
		t.Fatal(err)
	}
	var doc struct {
		Tables []struct {
			Name    string
			Columns []struct {
				Name       string
				Type       string
				Nullable   bool
				Default    *string
				PrimaryKey bool `json:"primaryKey"`
				References *struct{ Table, Column string }
			}
			Rows []map[string]any
		}
	}
	if err := json.Unmarshal(b, &doc); err != nil {
		t.Fatal(err)
	}
	if len(doc.Tables) != 1 || doc.Tables[0].Name != "book" || len(doc.Tables[0].Rows) != 1 {
		t.Fatalf("got %s", b)
	}
	cols := map[string]int{}
	for i, c := range doc.Tables[0].Columns {
		cols[c.Name] = i
	}
	id, author, price := doc.Tables[0].Columns[cols["id"]], doc.Tables[0].Columns[cols["author_id"]], doc.Tables[0].Columns[cols["price"]]
	if !id.PrimaryKey || id.Nullable || id.Default == nil || author.References == nil || author.References.Table != "author" || author.References.Column != "id" || !price.Nullable || price.Type != "numeric(6,2)" {
		t.Fatalf("got %s", b)
	}
	if row := doc.Tables[0].Rows[0]; row["title"] != "A Wizard of Earthsea" || row["id"] != float64(1) || row["price"] != 9.9 {
		t.Fatalf("row %v", row)
	}
}

func TestRunSQLRollsBackByDefault(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	ctx := context.Background()
	res, err := RunSQL(ctx, pool, "UPDATE book SET title = upper(title) WHERE id = $1", []string{"1"}, false)
	if err != nil || res.Affected != 1 || res.Committed || res.IsQuery {
		t.Fatalf("%+v %v", res, err)
	}
	var title string
	if err := pool.QueryRow(ctx, "SELECT title FROM book WHERE id = 1").Scan(&title); err != nil || title != "A Wizard of Earthsea" {
		t.Fatalf("not rolled back: %q %v", title, err)
	}
	res, err = RunSQL(ctx, pool, "UPDATE book SET title = upper(title) WHERE id = $1", []string{"1"}, true)
	if err != nil || !res.Committed {
		t.Fatalf("%+v %v", res, err)
	}
	if err := pool.QueryRow(ctx, "SELECT title FROM book WHERE id = 1").Scan(&title); err != nil || title != "A WIZARD OF EARTHSEA" {
		t.Fatalf("not committed: %q %v", title, err)
	}
}

func TestRunSQLRows(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	res, err := RunSQL(context.Background(), pool, "SELECT id, title, price FROM book WHERE author_id = $1 AND published ORDER BY id", []string{"1"}, false)
	if err != nil || !res.IsQuery || len(res.Rows.Data) != 2 {
		t.Fatalf("%+v %v", res, err)
	}
	var out bytes.Buffer
	res.WriteText(&out)
	if want := "id | title                     | price\n1  | A Wizard of Earthsea      | 9.90\n2  | The Left Hand of Darkness | 12.50\n"; out.String() != want {
		t.Fatalf("got:\n%s\nwant:\n%s", out.String(), want)
	}
	out.Reset()
	res.WriteJSON(&out)
	var rows []map[string]any
	if err := json.Unmarshal(out.Bytes(), &rows); err != nil || len(rows) != 2 || rows[0]["id"] != float64(1) || rows[0]["price"] != 9.9 {
		t.Fatalf("got %s (%v)", out.String(), err)
	}
}

func TestRunSQLReturningInsideRolledBackTransaction(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	res, err := RunSQL(context.Background(), pool, "INSERT INTO author (name) VALUES ($1) RETURNING id, name", []string{"Octavia Butler"}, false)
	if err != nil || !res.IsQuery || len(res.Rows.Data) != 1 || res.Affected != 1 {
		t.Fatalf("%+v %v", res, err)
	}
	var n int
	_ = pool.QueryRow(context.Background(), "SELECT count(*) FROM author").Scan(&n)
	if n != 2 {
		t.Fatalf("author count %d", n)
	}
	var out bytes.Buffer
	(&Result{Affected: 3}).WriteText(&out)
	if out.String() != "3 rows affected\n" {
		t.Fatalf("got %q", out.String())
	}
}

func TestRunSQLErrorsAreAPIErrors(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	for query, code := range map[string]apierr.Code{
		"SELEC 1":                           apierr.QuerySyntaxError,
		"SELECT * FROM nope":                apierr.UnknownTable,
		"SELECT 1; SELECT 2":                "",
		"INSERT INTO author DEFAULT VALUES": apierr.ConstraintViolation,
	} {
		_, err := RunSQL(context.Background(), pool, query, nil, false)
		e, ok := apierr.As(err)
		if err == nil || !ok || (code != "" && e.Code != code) || e.Hint == "" {
			t.Errorf("%q: %#v", query, err)
		}
	}
}
