package resource

import (
	"context"
	"net/http"
	"strings"
	"testing"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

func listExec(d *Definition, page int, filters map[string]any) *Exec {
	ex := &Exec{Page: page, ItemsPerPage: d.ItemsPerPage, Filters: map[string]any{}, Values: map[string]any{}}
	if id := d.Identifier().Name; id != "" {
		ex.Order = []sqlf.Order{{Column: id}}
	}
	for k, v := range filters {
		ex.Where = append(ex.Where, sqlf.Eq(k, v))
		ex.Filters[k] = v
	}
	return ex
}

func testPool(t *testing.T) *pgxpool.Pool {
	return testdb.Pool(t, testdb.Fixture)
}

const bookQuery = "export default function query(ctx: QueryContext): Sql {\n  return ctx.sql`SELECT b.id, b.title, a.name AS author, b.price, b.released_at\n    FROM book b JOIN author a ON a.id = b.author_id\n    WHERE b.published AND b.tenant = ${ctx.request.header('x-tenant') ?? 'acme'}`;\n}"

const bookDef = `{"name":"Book","path":"/books","query":"book.query.ts","itemsPerPage":2,"properties":{
 "id":{"type":"integer","identifier":true},"title":{"type":"string"},"author":{"type":"string","filterable":true},
 "price":{"type":"number"},"released_at":{"type":"datetime"}}}`

func checkFiles(t *testing.T, pool *pgxpool.Pool, files map[string]string) (*Set, apierr.List) {
	t.Helper()
	set, errs, _ := load(t, files)
	if len(errs) != 0 {
		t.Fatalf("static errors: %s", errs.Error())
	}
	return set, Check(context.Background(), &Runtime{Pool: pool}, set)
}

func TestCheckValidDefinitions(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{"Author.json": authorJSON, "Book.json": bookDef, "book.query.ts": bookQuery})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
}

func TestCheckUnknownTableListsTables(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{"Author.json": strings.Replace(authorJSON, `"author"`, `"authors"`, 1)})
	e := expectOnly(t, errs, apierr.UnknownTable)
	if e.Pointer != "/table" || !strings.Contains(e.Hint, "author, book") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestCheckUnknownTableColumnListsColumns(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{"A.json": `{"name":"A","path":"/a","table":"book","properties":{"id":{"type":"integer","identifier":true},"author":{"type":"string"}}}`})
	e := expectOnly(t, errs, apierr.UnknownColumn)
	if e.Pointer != "/properties/author" || !strings.Contains(e.Hint, "author_id") || !strings.Contains(e.Hint, "query") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestCheckQueryMissingColumnListsReturnedColumns(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{
		"Book.json":     `{"name":"Book","path":"/books","query":"book.query.ts","properties":{"id":{"type":"integer","identifier":true},"author":{"type":"string"}}}`,
		"book.query.ts": "export default (ctx) => ctx.sql`SELECT b.id, a.name AS author_name FROM book b JOIN author a ON a.id = b.author_id`;",
	})
	e := expectOnly(t, errs, apierr.UnknownColumn)
	if e.Pointer != "/properties/author" || !strings.Contains(e.Hint, "id, author_name") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestCheckCaseFoldingHint(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{
		"Book.json":     `{"name":"Book","path":"/books","query":"book.query.ts","properties":{"id":{"type":"integer","identifier":true},"authorName":{"type":"string"}}}`,
		"book.query.ts": "export default (ctx) => ctx.sql`SELECT b.id, a.name AS authorName FROM book b JOIN author a ON a.id = b.author_id`;",
	})
	e := expectOnly(t, errs, apierr.UnknownColumn)
	if !strings.Contains(e.Hint, `AS "authorName"`) {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestCheckTypeMismatchSuggestsType(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{"A.json": `{"name":"A","path":"/a","table":"book","properties":{"id":{"type":"integer","identifier":true},"published":{"type":"string"},"price":{"type":"integer"}}}`})
	if len(errs) != 2 {
		t.Fatalf("unexpected %s", errs.Error())
	}
	for _, e := range errs {
		if e.Code != apierr.TypeMismatch {
			t.Fatalf("unexpected %+v", e)
		}
	}
	if !strings.Contains(errs[0].Hint, `"boolean"`) || !strings.Contains(errs[1].Hint, `"number"`) {
		t.Fatalf("unexpected hints %s", errs.Error())
	}
}

func TestCheckQuerySyntaxErrorLocatesLine(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{
		"Book.json":     `{"name":"Book","path":"/books","query":"book.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`,
		"book.query.ts": "export default (ctx) => ctx.sql`SELECT id\nFORM book`;",
	})
	e := expectOnly(t, errs, apierr.QuerySyntaxError)
	if e.Pointer != "/query" || !strings.Contains(e.Message, "line 2") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestCheckQueryUnknownTableListsTables(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{
		"Book.json":     `{"name":"Book","path":"/books","query":"book.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`,
		"book.query.ts": "export default (ctx) => ctx.sql`SELECT id FROM books`;",
	})
	e := expectOnly(t, errs, apierr.UnknownTable)
	if !strings.Contains(e.Hint, "author, book") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestCheckDuplicateColumns(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{
		"Book.json":     `{"name":"Book","path":"/books","query":"book.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`,
		"book.query.ts": "export default (ctx) => ctx.sql`SELECT b.id, a.id FROM book b JOIN author a ON a.id = b.author_id`;",
	})
	e := expectOnly(t, errs, apierr.DuplicateColumn)
	if !strings.Contains(e.Message, `"id"`) {
		t.Fatalf("unexpected %+v", e)
	}
}

func runtimeFor(t *testing.T) (*Runtime, *Set) {
	pool := testPool(t)
	set, errs := checkFiles(t, pool, map[string]string{"Author.json": authorJSON, "Book.json": bookDef, "book.query.ts": bookQuery})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	return &Runtime{Pool: pool}, set
}

func TestListComposesFiltersAndPaginationOverQuery(t *testing.T) {
	rt, set := runtimeFor(t)
	book := set.ByName("Book")
	req := script.Request{Method: "GET", Path: "/books", Header: http.Header{}}
	items, total, err := rt.List(context.Background(), book, req, listExec(book, 1, nil))
	if err != nil {
		t.Fatal(err)
	}
	if total != 3 || len(items) != 2 || items[0]["id"] != int64(1) || items[1]["id"] != int64(2) {
		t.Fatalf("page 1: total %d items %v", total, items)
	}
	items, _, _ = rt.List(context.Background(), book, req, listExec(book, 2, nil))
	if len(items) != 1 || items[0]["title"] != "Dune" || items[0]["price"] != 15.0 || items[0]["released_at"] != "1965-08-01T00:00:00Z" {
		t.Fatalf("page 2: %v", items)
	}
	items, total, _ = rt.List(context.Background(), book, req, listExec(book, 1, map[string]any{"author": "Frank Herbert"}))
	if total != 1 || len(items) != 1 || items[0]["title"] != "Dune" {
		t.Fatalf("filtered: %d %v", total, items)
	}
}

func TestQueryReadsRequestHeader(t *testing.T) {
	rt, set := runtimeFor(t)
	req := script.Request{Method: "GET", Path: "/books", Header: http.Header{"X-Tenant": {"other"}}}
	_, total, err := rt.List(context.Background(), set.ByName("Book"), req, listExec(set.ByName("Book"), 1, nil))
	if err != nil || total != 0 {
		t.Fatalf("expected empty tenant, got %d %v", total, err)
	}
}

func TestGetItemOverQuery(t *testing.T) {
	rt, set := runtimeFor(t)
	book := set.ByName("Book")
	req := script.Request{Method: "GET", Path: "/books/4", Header: http.Header{}}
	item, ok, err := rt.Get(context.Background(), book, req, int64(4), "read", nil)
	if err != nil || !ok || item["author"] != "Frank Herbert" {
		t.Fatalf("unexpected %v %v %v", item, ok, err)
	}
	_, ok, err = rt.Get(context.Background(), book, req, int64(3), "read", nil)
	if err != nil || ok {
		t.Fatalf("unpublished book must not be found: %v %v", ok, err)
	}
	author, ok, err := rt.Get(context.Background(), set.ByName("Author"), req, int64(1), "read", nil)
	if err != nil || !ok || author["name"] != "Ursula K. Le Guin" || author["id"] != int64(1) {
		t.Fatalf("unexpected %v %v %v", author, ok, err)
	}
}

func TestParseValue(t *testing.T) {
	cases := []struct {
		typ, in string
		want    any
		ok      bool
	}{
		{"integer", "42", int64(42), true},
		{"integer", "4x", nil, false},
		{"number", "1.5", 1.5, true},
		{"boolean", "true", true, true},
		{"boolean", "0", false, true},
		{"boolean", "yes", nil, false},
		{"string", "x", "x", true},
	}
	for _, c := range cases {
		got, err := ParseValue(c.typ, c.in)
		if (err == nil) != c.ok || (c.ok && got != c.want) {
			t.Errorf("ParseValue(%s, %s) = %v, %v", c.typ, c.in, got, err)
		}
	}
	if _, err := ParseValue("datetime", "2024-01-02T03:04:05Z"); err != nil {
		t.Error(err)
	}
}
