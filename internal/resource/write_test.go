package resource

import (
	"context"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
)

const bookWritable = `{"name":"Book","path":"/books","query":"book.query.ts","command":"book.command.ts","properties":{
 "id":{"type":"integer","identifier":true},"title":{"type":"string","required":true},"author_id":{"type":"integer","required":true},
 "author":{"type":"string","readOnly":true},"published":{"type":"boolean"}}}`

const bookWritableQuery = "export default (ctx) => ctx.sql`SELECT b.id, b.title, b.author_id, a.name AS author, b.published FROM book b JOIN author a ON a.id = b.author_id`;"

const bookCommand = `export function create(input, ctx) {
  const [author] = ctx.db.query(ctx.sql` + "`SELECT name FROM author WHERE id = ${input.author_id}`" + `);
  if (!author) ctx.reject({ code: "unknown_author", message: "author " + input.author_id + " does not exist", pointer: "/author_id", hint: "Create the author first." });
  const [row] = ctx.db.query(ctx.sql` + "`INSERT INTO book (title, author_id, published) VALUES (${input.title}, ${input.author_id}, ${input.published ?? false}) RETURNING id`" + `);
  return row.id;
}
export function update(input, ctx) {
  if (input.title !== undefined) ctx.db.exec(ctx.sql` + "`UPDATE book SET title = ${input.title} WHERE id = ${ctx.id}`" + `);
}
export function remove(input, ctx) {
  ctx.db.exec(ctx.sql` + "`DELETE FROM book WHERE id = ${ctx.id}`" + `);
}`

func TestLoadCommandErrors(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","command":"a.command.ts","properties":{"id":{"type":"integer","identifier":true}}}`})
	expectOnly(t, errs, apierr.CommandFileNotFound)

	_, errs, _ = load(t, map[string]string{
		"A.json":       `{"name":"A","path":"/a","table":"a","command":"a.command.ts","properties":{"id":{"type":"integer","identifier":true}}}`,
		"a.command.ts": "export function insert() {}",
	})
	if e := expectOnly(t, errs, apierr.InvalidExport); !strings.Contains(e.Message, "insert") || !strings.Contains(e.Hint, "remove") {
		t.Fatalf("unexpected %+v", e)
	}

	_, errs, _ = load(t, map[string]string{
		"A.json":       `{"name":"A","path":"/a","table":"a","readOnly":true,"command":"a.command.ts","properties":{"id":{"type":"integer","identifier":true}}}`,
		"a.command.ts": "export function create() {}",
	})
	if e := expectOnly(t, errs, apierr.InvalidValue); e.Pointer != "/readOnly" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestAllowFetchPatternHint(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","allow":{"fetch":["https://api.example.com/x"]},"properties":{"id":{"type":"integer","identifier":true}}}`})
	e := expectOnly(t, errs, apierr.InvalidValue)
	if e.Pointer != "/allow/fetch/0" || !strings.Contains(e.Hint, "without scheme") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestOperations(t *testing.T) {
	set, errs, _ := load(t, map[string]string{
		"Author.json":     authorJSON,
		"Ro.json":         `{"name":"Ro","path":"/ro","table":"author","readOnly":true,"properties":{"id":{"type":"integer","identifier":true}}}`,
		"Q.json":          `{"name":"Q","path":"/q","query":"q.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`,
		"q.query.ts":      "export default (ctx) => ctx.sql`SELECT 1 AS id`;",
		"Book.json":       bookWritable,
		"book.query.ts":   bookWritableQuery,
		"book.command.ts": "export function create() {} export function remove() {}",
	})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	cases := map[string][]string{"Author": CommandOperations, "Ro": nil, "Q": nil, "Book": {"create", "delete"}}
	for name, want := range cases {
		if got := set.ByName(name).Operations(); !reflect.DeepEqual(got, want) {
			t.Errorf("%s: got %v want %v", name, got, want)
		}
	}
}

func bookDefinition(t *testing.T) *Definition {
	set, errs, _ := load(t, map[string]string{"Book.json": bookWritable, "book.query.ts": bookWritableQuery, "book.command.ts": bookCommand})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	return set.ByName("Book")
}

func TestValidateInput(t *testing.T) {
	d := bookDefinition(t)
	out, errs := ValidateInput(d, ModeCreate, map[string]any{"@type": "Book", "title": "Dune", "author_id": float64(2), "published": true}, nil)
	if len(errs) != 0 || !reflect.DeepEqual(out, map[string]any{"title": "Dune", "author_id": int64(2), "published": true}) {
		t.Fatalf("unexpected %v %s", out, errs.Error())
	}

	_, errs = ValidateInput(d, ModeCreate, map[string]any{"titel": "x", "author": "y", "author_id": "2"}, nil)
	codes := map[string]apierr.Code{}
	for _, e := range errs {
		codes[e.Pointer] = e.Code
	}
	want := map[string]apierr.Code{"/titel": apierr.UnknownProperty, "/author": apierr.ReadOnlyProperty, "/author_id": apierr.InvalidPropertyValue, "/title": apierr.RequiredProperty}
	if !reflect.DeepEqual(codes, want) {
		t.Fatalf("got %v", codes)
	}
	for _, e := range errs {
		if e.Code == apierr.UnknownProperty && !strings.Contains(e.Hint, `"title"`) {
			t.Fatalf("missing suggestion %+v", e)
		}
	}

	if _, errs = ValidateInput(d, ModePatch, map[string]any{"published": true}, int64(1)); len(errs) != 0 {
		t.Fatalf("patch must not require properties: %s", errs.Error())
	}
	if _, errs = ValidateInput(d, ModeReplace, map[string]any{"id": float64(2), "title": "x", "author_id": float64(1)}, int64(1)); len(errs) != 1 || errs[0].Pointer != "/id" {
		t.Fatalf("identifier mismatch expected: %s", errs.Error())
	}
}

func TestGeneratedWrites(t *testing.T) {
	pool := testPool(t)
	set, errs := checkFiles(t, pool, map[string]string{"Author.json": authorJSON})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	rt := &Runtime{Pool: pool}
	d := set.ByName("Author")
	ctx := context.Background()
	req := script.Request{Header: http.Header{}}
	newID, err := rt.Write(ctx, d, "create", req, nil, map[string]any{"name": "Octavia Butler"}, nil)
	if err != nil || newID != int64(3) {
		t.Fatalf("create: %v %v", newID, err)
	}
	if _, err := rt.Write(ctx, d, "update", req, int64(3), map[string]any{"name": "Octavia E. Butler"}, nil); err != nil {
		t.Fatal(err)
	}
	item, _, _ := rt.Get(ctx, d, req, int64(3), "read", nil)
	if item["name"] != "Octavia E. Butler" {
		t.Fatalf("update not applied: %v", item)
	}
	if _, err := rt.Write(ctx, d, "delete", req, int64(3), map[string]any{}, nil); err != nil {
		t.Fatal(err)
	}
	if _, ok, _ := rt.Get(ctx, d, req, int64(3), "read", nil); ok {
		t.Fatal("delete not applied")
	}

	_, err = rt.Write(ctx, d, "create", req, nil, map[string]any{"name": nil}, nil)
	e, _ := apierr.As(err)
	if e == nil || e.Code != apierr.ConstraintViolation || e.Pointer != "/name" {
		t.Fatalf("not null violation: %#v", err)
	}

	_, err = rt.Write(ctx, d, "delete", req, int64(1), map[string]any{}, nil)
	if e, _ := apierr.As(err); e == nil || e.Code != apierr.InvalidReference {
		t.Fatalf("delete referenced author: %#v", err)
	}
}

func TestCommandWrites(t *testing.T) {
	pool := testPool(t)
	set, errs := checkFiles(t, pool, map[string]string{"Book.json": bookWritable, "book.query.ts": bookWritableQuery, "book.command.ts": bookCommand})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	rt := &Runtime{Pool: pool}
	d := set.ByName("Book")
	ctx := context.Background()
	req := script.Request{Header: http.Header{}}
	newID, err := rt.Write(ctx, d, "create", req, nil, map[string]any{"title": "Children of Dune", "author_id": int64(2)}, nil)
	if err != nil || newID != int64(5) {
		t.Fatalf("create: %v %v", newID, err)
	}
	item, ok, _ := rt.Get(ctx, d, req, newID, "read", nil)
	if !ok || item["author"] != "Frank Herbert" || item["published"] != false {
		t.Fatalf("read back: %v", item)
	}
	_, err = rt.Write(ctx, d, "create", req, nil, map[string]any{"title": "x", "author_id": int64(42)}, nil)
	e, _ := apierr.As(err)
	if e == nil || e.Code != "unknown_author" || e.Pointer != "/author_id" || e.Status() != 422 || e.Resource != "Book" {
		t.Fatalf("reject: %#v", err)
	}
	if _, err := rt.Write(ctx, d, "update", req, int64(5), map[string]any{"title": "Children"}, nil); err != nil {
		t.Fatal(err)
	}
	if _, err := rt.Write(ctx, d, "delete", req, int64(5), map[string]any{}, nil); err != nil {
		t.Fatal(err)
	}
	if _, ok, _ := rt.Get(ctx, d, req, int64(5), "read", nil); ok {
		t.Fatal("delete via remove() not applied")
	}
}

const quoteDef = `{"name":"Quote","path":"/quotes","query":"quote.query.ts","itemsPerPage":2,"allow":{"fetch":["%HOST%"]},"properties":{
 "id":{"type":"integer","identifier":true},"text":{"type":"string"},"author":{"type":"string","filterable":true}}}`

const quoteQuery = `export default async function (ctx) {
  const res = await ctx.fetch("%URL%/quotes.json");
  let rows = (await res.json()).map((q) => ({ id: q.id, text: q.quote, author: q.by }));
  if (ctx.operation === "item") return rows.find((r) => r.id === ctx.id) ?? null;
  if (ctx.filters.author !== undefined) rows = rows.filter((r) => r.author === ctx.filters.author);
  const start = (ctx.page - 1) * ctx.itemsPerPage;
  return { items: rows.slice(start, start + ctx.itemsPerPage), totalItems: rows.length };
}`

func quoteServer(t *testing.T) *httptest.Server {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`[{"id":1,"quote":"a","by":"X"},{"id":2,"quote":"b","by":"Y"},{"id":3,"quote":"c","by":"X"}]`))
	}))
	t.Cleanup(srv.Close)
	return srv
}

func TestNonSQLQueryOverFetch(t *testing.T) {
	pool := testPool(t)
	srv := quoteServer(t)
	host := strings.TrimPrefix(srv.URL, "http://")
	set, errs := checkFiles(t, pool, map[string]string{
		"Quote.json":     strings.ReplaceAll(quoteDef, "%HOST%", host),
		"quote.query.ts": strings.ReplaceAll(quoteQuery, "%URL%", srv.URL),
	})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	rt := &Runtime{Pool: pool}
	d := set.ByName("Quote")
	ctx := context.Background()
	req := script.Request{Header: http.Header{}}
	items, total, err := rt.List(ctx, d, req, listExec(d, 2, nil))
	if err != nil || total != 3 || len(items) != 1 || items[0]["id"] != int64(3) {
		t.Fatalf("list: %v %d %v", items, total, err)
	}
	items, total, _ = rt.List(ctx, d, req, listExec(d, 1, map[string]any{"author": "Y"}))
	if total != 1 || items[0]["text"] != "b" {
		t.Fatalf("filtered: %v", items)
	}
	item, ok, err := rt.Get(ctx, d, req, int64(2), "read", nil)
	if err != nil || !ok || item["author"] != "Y" {
		t.Fatalf("item: %v %v %v", item, ok, err)
	}
	if _, ok, _ := rt.Get(ctx, d, req, int64(9), "read", nil); ok {
		t.Fatal("missing item must not be found")
	}
}

func TestValidationDoesNotCallFetch(t *testing.T) {
	pool := testPool(t)
	hits := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits++
		_, _ = w.Write([]byte(`[]`))
	}))
	t.Cleanup(srv.Close)
	set, errs := checkFiles(t, pool, map[string]string{
		"Quote.json":     strings.ReplaceAll(quoteDef, "%HOST%", "example.com"),
		"quote.query.ts": strings.ReplaceAll(quoteQuery, "%URL%", srv.URL),
	})
	if len(errs) != 0 || hits != 0 {
		t.Fatalf("validation must stop before ctx.fetch: hits %d, %s", hits, errs.Error())
	}
	_, _, err := (&Runtime{Pool: pool}).List(context.Background(), set.ByName("Quote"), script.Request{Header: http.Header{}}, listExec(set.ByName("Quote"), 1, nil))
	if e, _ := apierr.As(err); e == nil || e.Code != apierr.FetchDenied || !strings.Contains(e.Hint, strings.TrimPrefix(srv.URL, "http://")) {
		t.Fatalf("the denied fetch surfaces at runtime: %#v", err)
	}
}

func TestValidationStillProbesSQLQueriesUsingEnv(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{
		"Book.json":     `{"name":"Book","path":"/books","query":"book.query.ts","allow":{"env":["TENANT"]},"properties":{"id":{"type":"integer","identifier":true},"nope":{"type":"string"}}}`,
		"book.query.ts": "export default (ctx) => ctx.sql`SELECT id FROM book WHERE tenant = ${ctx.env('TENANT')}`;",
	})
	if e := expectOnly(t, errs, apierr.UnknownColumn); e.Pointer != "/properties/nope" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestNonSQLRowsAreValidated(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{
		"Quote.json":     strings.ReplaceAll(quoteDef, "%HOST%", "x"),
		"quote.query.ts": "export default () => ({ items: [{ id: 1, text: 'a' }], totalItems: 1 });",
	})
	if e := expectOnly(t, errs, apierr.InvalidRow); !strings.Contains(e.Message, `"author"`) {
		t.Fatalf("unexpected %+v", e)
	}
	_, errs = checkFiles(t, pool, map[string]string{
		"Quote.json":     strings.ReplaceAll(quoteDef, "%HOST%", "x"),
		"quote.query.ts": "export default () => [{ id: 1, text: 'a', author: 'b' }];",
	})
	if e := expectOnly(t, errs, apierr.InvalidQueryResult); !strings.Contains(e.Hint, "totalItems") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestStreamIgnoresPagination(t *testing.T) {
	rt, set := runtimeFor(t)
	var titles []string
	err := rt.Stream(context.Background(), set.ByName("Book"), script.Request{Header: http.Header{}}, listExec(set.ByName("Book"), 1, nil), func(it Item) error {
		titles = append(titles, it["title"].(string))
		return nil
	})
	if err != nil || len(titles) != 3 || titles[2] != "Dune" {
		t.Fatalf("unexpected %v %v", titles, err)
	}
}

const quoteStar = `def query(ctx):
    res = ctx.fetch("%URL%/quotes.json")
    rows = [{"id": q["id"], "text": q["quote"], "author": q["by"]} for q in res.json()]
    if ctx.operation == "item":
        for r in rows:
            if r["id"] == ctx.id:
                return r
        return None
    if "author" in ctx.filters:
        rows = [r for r in rows if r["author"] == ctx.filters["author"]]
    start = (ctx.page - 1) * ctx.items_per_page
    return {"items": rows[start:start + ctx.items_per_page], "total_items": len(rows)}
`

func TestStarlarkQueryOverFetch(t *testing.T) {
	pool := testPool(t)
	srv := quoteServer(t)
	host := strings.TrimPrefix(srv.URL, "http://")
	set, errs, _ := Load(writeDir(t, map[string]string{
		"Quote.json": strings.ReplaceAll(strings.ReplaceAll(quoteDef, "%HOST%", host), "quote.query.ts", "quote.star"),
		"quote.star": strings.ReplaceAll(quoteStar, "%URL%", srv.URL),
	}), script.NewEngine(time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	if errs := Check(context.Background(), &Runtime{Pool: pool}, set); len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	rt := &Runtime{Pool: pool}
	d := set.ByName("Quote")
	req := script.Request{Header: http.Header{}}
	items, total, err := rt.List(context.Background(), d, req, listExec(d, 1, map[string]any{"author": "X"}))
	if err != nil || total != 2 || len(items) != 2 || items[1]["id"] != int64(3) {
		t.Fatalf("list: %v %d %v", items, total, err)
	}
	item, ok, err := rt.Get(context.Background(), d, req, int64(2), "read", nil)
	if err != nil || !ok || item["text"] != "b" {
		t.Fatalf("item: %v %v %v", item, ok, err)
	}
}
