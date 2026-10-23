package httpapi

import (
	"context"
	"encoding/json"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

var shopExtra = map[string]string{
	"Purchase.json": `{
  "name": "Purchase", "path": "/purchases", "query": "purchase.query.ts", "command": "purchase.command.ts",
  "order": {"createdAt": "desc"},
  "parameters": {
    "Customer-Id": {"in": "header", "type": "integer", "required": true, "status": 401, "handler": "customer.handler.ts"}
  },
  "input": {
    "lines": {"type": "array", "required": true, "minItems": 1, "items": {"type": "object", "properties": {
      "bookId": {"type": "integer", "required": true},
      "quantity": {"type": "integer", "required": true, "min": 1}
    }}}
  },
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "createdAt": {"type": "datetime"},
    "totalCents": {"type": "integer"},
    "lines": {"type": "array", "items": {"type": "object", "properties": {"bookId": {"type": "integer"}, "quantity": {"type": "integer"}}}}
  }
}`,
	"purchase.query.ts": "export default (ctx) => ctx.sql`SELECT p.id, p.created_at AS \"createdAt\", p.total_cents AS \"totalCents\",\n" +
		"  (SELECT coalesce(json_agg(json_build_object('bookId', l.book_id, 'quantity', l.quantity) ORDER BY l.id), '[]') FROM purchase_line l WHERE l.purchase_id = p.id) AS lines\n" +
		"  FROM purchase p WHERE p.customer_id = ${ctx.get('customer')}`;",
	"customer.handler.ts": "export default function (id, ctx) {\n" +
		"  const [c] = ctx.db.query(ctx.sql`SELECT id FROM customer WHERE id = ${id}`);\n" +
		"  if (!c) ctx.reject({ message: `unknown customer ${id}` });\n" +
		"  ctx.set('customer', c.id);\n" +
		"}",
	"purchase.command.ts": "export function create(input, ctx) {\n" +
		"  const [p] = ctx.db.query(ctx.sql`INSERT INTO purchase (customer_id, total_cents) VALUES (${ctx.get('customer')}, 0) RETURNING id`);\n" +
		"  for (const l of input.lines) ctx.db.exec(ctx.sql`INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (${p.id}, ${l.bookId}, ${l.quantity}, 0)`);\n" +
		"  return p.id;\n" +
		"}",
	"PurchaseSettle.json": `{
  "name": "PurchaseSettle", "query": "purchase.query.ts", "command": "settle.command.ts",
  "parameters": {
    "Customer-Id": {"in": "header", "type": "integer", "required": true, "status": 401, "handler": "customer.handler.ts"}
  },
  "operations": [{"method": "POST", "path": "/purchases/{id}/settle", "input": {}}],
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "createdAt": {"type": "datetime"},
    "totalCents": {"type": "integer"},
    "lines": {"type": "array", "items": {"type": "object", "properties": {"bookId": {"type": "integer"}, "quantity": {"type": "integer"}}}}
  }
}`,
	"settle.command.ts": "export function create(input, ctx) {\n" +
		"  ctx.db.exec(ctx.sql`UPDATE purchase SET total_cents = 1 WHERE id = ${ctx.id}`);\n" +
		"}",
	"PurchaseMark.json": `{
  "name": "PurchaseMark", "query": "mark.query.ts", "command": "mark.command.ts",
  "operations": [
    {"method": "GET", "path": "/marks", "output": ["totalCents"], "format": "jsonld"},
    {"method": "GET", "path": "/marks/{id}", "output": ["totalCents"], "format": "jsonld"},
    {"method": "POST", "path": "/marks/{id}/post", "input": {}, "output": ["totalCents"]},
    {"method": "PUT", "path": "/marks/{id}/put", "input": {}, "output": ["totalCents"]},
    {"method": "PATCH", "path": "/marks/{id}", "input": {}, "output": ["totalCents"], "format": "jsonld"},
    {"method": "DELETE", "path": "/marks/{id}", "output": ["totalCents"]}
  ],
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "totalCents": {"type": "integer"}
  }
}`,
	"mark.query.ts": "export default (ctx) => ctx.sql`SELECT p.id, p.total_cents AS \"totalCents\" FROM purchase p`;",
	"mark.command.ts": "const mark = (cents, ctx) => ctx.db.exec(ctx.sql`UPDATE purchase SET total_cents = ${cents} WHERE id = ${ctx.id}`);\n" +
		"export function create(input, ctx) { mark(701, ctx); }\n" +
		"export function update(input, ctx) { mark(702, ctx); }\n" +
		"export function remove(input, ctx) { mark(704, ctx); }",
	"PurchasePage.json": `{
  "name": "PurchasePage", "query": "mark.query.ts", "command": "page.command.ts",
  "operations": [{"method": "POST", "path": "/pages/{id}/publish", "input": {}, "output": ["totalCents"]}],
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "totalCents": {"type": "integer"}
  }
}`,
	"page.command.ts": "export function create(input, ctx) {\n" +
		"  ctx.db.exec(ctx.sql`UPDATE purchase SET total_cents = 705 WHERE id = ${ctx.id}`);\n" +
		"  if (ctx.request.accepts('text/html')) return ctx.response({ headers: { 'Content-Type': 'text/html; charset=utf-8' }, body: '<h1>published</h1>' });\n" +
		"}",
	"Verify.json": `{
  "name": "Verify",
  "operations": [{"method": ["GET", "POST"], "path": "/verify/{id}", "query": "verify.query.ts", "description": "One-click verification"}],
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "totalCents": {"type": "integer"}
  }
}`,
	"verify.query.ts": "export default function query(ctx) {\n" +
		"  ctx.db.exec(ctx.sql`UPDATE purchase SET total_cents = 900 WHERE id = ${ctx.id}`);\n" +
		"  if (ctx.request.accepts('text/html')) return ctx.response({ headers: { 'Content-Type': 'text/html; charset=utf-8', 'X-Method': ctx.request.method }, body: '<h1>verified</h1>' });\n" +
		"  return ctx.sql`SELECT p.id, p.total_cents AS \"totalCents\" FROM purchase p`;\n" +
		"}",
	"PurchaseSearch.json": `{
  "name": "PurchaseSearch", "query": "mark.query.ts",
  "operations": [{"method": ["GET", "POST"], "path": "/searches", "query": "mark.query.ts"}],
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "totalCents": {"type": "integer"}
  }
}`,
	"PurchaseTouch.json": `{
  "name": "PurchaseTouch", "query": "mark.query.ts",
  "operations": [{"method": ["POST", "PUT"], "path": "/touch/{id}", "command": "touch.command.ts", "input": {}, "output": ["totalCents"]}],
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "totalCents": {"type": "integer"}
  }
}`,
	"touch.command.ts": "const touch = (ctx) => ctx.db.exec(ctx.sql`UPDATE purchase SET total_cents = ${ctx.request.method === 'POST' ? 710 : 711} WHERE id = ${ctx.id}`);\n" +
		"export function create(input, ctx) { touch(ctx); }\n" +
		"export function update(input, ctx) { touch(ctx); }",
	"SalesReport.json": `{
  "name": "SalesReport",
  "operations": [{"method": "GET", "path": "/reports/sales", "format": "csv", "query": "sales.query.ts"}],
  "parameters": {
    "from": {"in": "query", "type": "date", "required": true},
    "to": {"in": "query", "type": "date", "required": true}
  },
  "properties": {"title": {"type": "string"}, "quantity": {"type": "integer"}}
}`,
	"sales.query.ts": "export default function query(ctx) {\n" +
		"  if (ctx.get('from') > ctx.get('to')) ctx.reject({ status: 400, message: 'from is after to' });\n" +
		"  if (ctx.request.query('boom') !== null) return ctx.sql`SELECT 'x' AS title, 1/0 AS quantity`;\n" +
		"  return ctx.sql`SELECT b.title, sum(l.quantity)::int AS quantity FROM purchase_line l JOIN purchase p ON p.id = l.purchase_id JOIN book b ON b.id = l.book_id\n" +
		"    WHERE p.created_at >= ${ctx.get('from')}::date AND p.created_at < ${ctx.get('to')}::date + 1 GROUP BY b.id, b.title ORDER BY quantity DESC, b.title, b.id`;\n" +
		"}",
}

func shopServer(t *testing.T, out Output) (*Server, *pgxpool.Pool) {
	t.Helper()
	pool := testdb.Shop(t)
	dir := t.TempDir()
	for _, files := range []map[string]string{testdb.ShopFiles, shopExtra} {
		for n, c := range files {
			if err := os.WriteFile(filepath.Join(dir, n), []byte(c), 0o644); err != nil {
				t.Fatal(err)
			}
		}
	}
	set, errs, _ := resource.Load(dir, script.NewEngine(5*time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	rt := &resource.Runtime{Pool: pool, Set: set}
	if errs := resource.Check(context.Background(), rt, set); len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	return &Server{Set: set, Runtime: rt, Output: out}, pool
}

var plainJSON = Output{
	Formats:  []string{"json", "csv"},
	Envelope: map[string]string{"items": "items", "total": "total", "page": "page", "itemsPerPage": "itemsPerPage", "pages": "pages"},
	Errors:   resource.ErrorShape{"error": "message"},
}

func call(t *testing.T, s *Server, method, target string, headers map[string]string, body string) (*httptest.ResponseRecorder, any) {
	t.Helper()
	req := httptest.NewRequest(method, target, strings.NewReader(body))
	if body != "" {
		req.Header.Set("Content-Type", "application/json")
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	rec := httptest.NewRecorder()
	s.ServeHTTP(rec, req)
	var out any
	_ = json.Unmarshal(rec.Body.Bytes(), &out)
	return rec, out
}

func ids(t *testing.T, doc any) []int {
	t.Helper()
	m, _ := doc.(map[string]any)
	items, ok := m["items"].([]any)
	if !ok {
		t.Fatalf("no items in %v", doc)
	}
	out := []int{}
	for _, it := range items {
		out = append(out, int(it.(map[string]any)["id"].(float64)))
	}
	return out
}

func wantError(t *testing.T, rec *httptest.ResponseRecorder, doc any, status int) string {
	t.Helper()
	m, _ := doc.(map[string]any)
	msg, _ := m["error"].(string)
	if code, _ := m["code"].(string); rec.Code != status || msg == "" || code == "" || !strings.HasPrefix(rec.Header().Get("Content-Type"), "application/json") {
		t.Fatalf("want %d {\"error\"}, got %d %s %s", status, rec.Code, rec.Header().Get("Content-Type"), rec.Body)
	}
	return msg
}

func TestEnvelopeAndErrorShape(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	rec, doc := call(t, s, "GET", "/books?itemsPerPage=3&page=2", nil, "")
	m := doc.(map[string]any)
	if rec.Code != 200 || m["total"] != 14.0 || m["page"] != 2.0 || m["itemsPerPage"] != 3.0 || !reflect.DeepEqual(ids(t, doc), []int{5, 6, 7}) {
		t.Fatalf("got %d %s", rec.Code, rec.Body)
	}
	rec, doc = call(t, s, "GET", "/books?page=9", nil, "")
	if rec.Code != 200 || len(ids(t, doc)) != 0 {
		t.Fatalf("page past the last: %d %s", rec.Code, rec.Body)
	}
	for _, bad := range []string{"itemsPerPage=0", "itemsPerPage=51", "page=0", "order[stock]=asc", "order[title]=up", "priceCents[gte]=x", "publishedAt[after]=2020-02-30", "inStock=maybe"} {
		rec, doc = call(t, s, "GET", "/books?"+bad, nil, "")
		wantError(t, rec, doc, 400)
	}
	rec, doc = call(t, s, "GET", "/books/abc", nil, "")
	wantError(t, rec, doc, 404)
	rec, doc = call(t, s, "GET", "/nowhere", nil, "")
	wantError(t, rec, doc, 404)
	rec, _ = call(t, s, "GET", "/books/4", nil, "")
	if rec.Body.String() != `{"id":4,"title":"Dune","priceCents":2000,"publishedAt":"1965-08-01","stock":10,"author":{"id":2,"name":"Frank Herbert"}}`+"\n" {
		t.Fatalf("item: %s", rec.Body)
	}
}

func TestEnvelopePagesRoundsUp(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	for target, want := range map[string]float64{
		"/books?itemsPerPage=3":           5,
		"/books?itemsPerPage=7":           2,
		"/books?itemsPerPage=14&page=1":   1,
		"/books?itemsPerPage=3&page=9":    5,
		"/books?title=no-such-book-title": 0,
	} {
		rec, doc := call(t, s, "GET", target, nil, "")
		if m, _ := doc.(map[string]any); rec.Code != 200 || m["pages"] != want {
			t.Errorf("%s: want pages %v, got %d %s", target, want, rec.Code, rec.Body)
		}
	}
}

func TestEnvelopeKeyOrderFollowsEnvelopeKeys(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	rec, _ := call(t, s, "GET", "/books?itemsPerPage=3", nil, "")
	if !strings.HasSuffix(strings.TrimSpace(rec.Body.String()), `"itemsPerPage":3,"pages":5}`) {
		t.Fatalf("got %s", rec.Body)
	}
}

func TestOperationWithIdentifierBeforeTheEndOfThePath(t *testing.T) {
	s, pool := shopServer(t, plainJSON)
	alice := map[string]string{"Customer-Id": "1"}
	total := func(id int) int {
		var n int
		_ = pool.QueryRow(context.Background(), "SELECT total_cents FROM purchase WHERE id = $1", id).Scan(&n)
		return n
	}
	rec, doc := call(t, s, "POST", "/purchases/2/settle", alice, "")
	wantError(t, rec, doc, 404)
	rec, doc = call(t, s, "POST", "/purchases/abc/settle", alice, "")
	wantError(t, rec, doc, 404)
	rec, doc = call(t, s, "POST", "/purchases/1/settle", nil, "")
	wantError(t, rec, doc, 401)
	rec, doc = call(t, s, "GET", "/purchases/1/settle", alice, "")
	wantError(t, rec, doc, 405)
	if total(1) != 5200 || total(2) != 3200 {
		t.Fatalf("refused calls changed rows: %d %d", total(1), total(2))
	}
	rec, doc = call(t, s, "POST", "/purchases/1/settle", alice, "")
	if m, _ := doc.(map[string]any); rec.Code != 200 || m["id"] != 1.0 || m["totalCents"] != 1.0 || total(1) != 1 {
		t.Fatalf("settle: %d %s", rec.Code, rec.Body)
	}
	rec, _ = call(t, s, "POST", "/purchases/5/settle", alice, `{"anything": [1, 2], "ignored": true}`)
	if rec.Code != 200 || total(5) != 1 {
		t.Fatalf("a body is ignored: %d %s", rec.Code, rec.Body)
	}
	rec, _ = call(t, s, "POST", "/purchases/6/settle", alice, `not json`)
	if rec.Code != 200 || total(6) != 1 {
		t.Fatalf("a body is ignored, even invalid JSON: %d %s", rec.Code, rec.Body)
	}
}

func TestBuiltInFiltersEndToEnd(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	cases := map[string][]int{
		"title=dune":                      {4, 5, 6, 11, 18},
		"title=DUNE&author=Frank+Herbert": {4, 5, 6, 11, 18},
		"title=%25":                       {},
		"title=_":                         {},
		"priceCents[gte]=1000&priceCents[lte]=2000":                    {1, 2, 4, 5, 6, 7, 14, 16, 17},
		"publishedAt[after]=2020-01-01&publishedAt[before]=2020-12-31": {10, 11, 12, 13},
		"inStock=false":                                  {2, 6, 12},
		"order[priceCents]=asc&itemsPerPage=6":           {10, 12, 5, 14, 1, 7},
		"order[title]=asc&order[priceCents]=desc&page=1": {1, 12, 6, 18, 4, 5, 16, 7, 13, 17},
		"order[publishedAt]=desc&itemsPerPage=4":         {17, 18, 11, 12},
		"author=Frank+Herbert&order[priceCents]=desc":    {18, 11, 4, 6, 5},
		"author=Nobody":                                  {},
	}
	for q, want := range cases {
		rec, doc := call(t, s, "GET", "/books?"+q, nil, "")
		if rec.Code != 200 || !reflect.DeepEqual(ids(t, doc), want) {
			t.Errorf("%s: got %d %v want %v", q, rec.Code, ids(t, doc), want)
		}
	}
}

func TestOperationOutputDiffersBetweenListAndItem(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	rec, _ := call(t, s, "GET", "/authors", nil, "")
	if !strings.Contains(rec.Body.String(), `{"id":4,"name":null,"publishedBooks":1}`) || strings.Contains(rec.Body.String(), `"books"`) {
		t.Fatalf("list: %s", rec.Body)
	}
	rec, _ = call(t, s, "GET", "/authors/5", nil, "")
	if rec.Body.String() != `{"id":5,"name":"Isaac Asimov","books":[]}`+"\n" {
		t.Fatalf("item: %s", rec.Body)
	}
	rec, doc := call(t, s, "GET", "/authors/x", nil, "")
	wantError(t, rec, doc, 404)
	rec, doc = call(t, s, "DELETE", "/authors/1", nil, "")
	wantError(t, rec, doc, 405)
}

func TestPatchLooksUpHiddenRowsAndReadsBack(t *testing.T) {
	s, pool := shopServer(t, plainJSON)
	rec, _ := call(t, s, "PATCH", "/books/3", nil, `{"stock": 9, "title": "The Dispossessed (2)"}`)
	if rec.Code != 204 || rec.Body.Len() != 0 {
		t.Fatalf("hidden after write: %d %s", rec.Code, rec.Body)
	}
	var stock int
	var title string
	_ = pool.QueryRow(context.Background(), "SELECT stock, title FROM book WHERE id = 3").Scan(&stock, &title)
	if stock != 9 || title != "The Dispossessed (2)" {
		t.Fatalf("not written: %d %q", stock, title)
	}
	rec, doc := call(t, s, "PATCH", "/books/4", nil, `{"priceCents": 2100, "isbn": "x"}`)
	if rec.Code != 200 || doc.(map[string]any)["priceCents"] != 2100.0 || doc.(map[string]any)["title"] != "Dune" {
		t.Fatalf("patch: %d %s", rec.Code, rec.Body)
	}
	for _, body := range []string{`{"title": null}`, `{"title": ""}`, `{"stock": -1}`, `{"priceCents": 1.5}`, `{"stock": "3"}`} {
		rec, doc := call(t, s, "PATCH", "/books/4", nil, body)
		wantError(t, rec, doc, 422)
	}
	_ = pool.QueryRow(context.Background(), "SELECT stock, title FROM book WHERE id = 4").Scan(&stock, &title)
	if stock != 10 || title != "Dune" {
		t.Fatalf("rejected patches changed the row: %d %q", stock, title)
	}
	rec, doc = call(t, s, "PATCH", "/books/999", nil, `{"stock": 1}`)
	wantError(t, rec, doc, 404)
	rec, doc = call(t, s, "GET", "/books/3", nil, "")
	wantError(t, rec, doc, 404)
}

func TestHeaderParameterIsCheckedFirst(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	for _, h := range []map[string]string{nil, {"Customer-Id": "abc"}, {"Customer-Id": "99"}} {
		for _, r := range []struct{ method, path, body string }{{"GET", "/purchases", ""}, {"GET", "/purchases/abc", ""}, {"POST", "/purchases", `{"lines": []}`}, {"POST", "/purchases", `not json`}} {
			rec, doc := call(t, s, r.method, r.path, h, r.body)
			wantError(t, rec, doc, 401)
		}
	}
	alice := map[string]string{"Customer-Id": "1"}
	rec, doc := call(t, s, "GET", "/purchases", alice, "")
	if rec.Code != 200 || !reflect.DeepEqual(ids(t, doc), []int{3, 1, 5, 6}) {
		t.Fatalf("most recent first: %d %s", rec.Code, rec.Body)
	}
	rec, doc = call(t, s, "GET", "/purchases/2", alice, "")
	wantError(t, rec, doc, 404)
	rec, doc = call(t, s, "GET", "/purchases/1", alice, "")
	if rec.Code != 200 || doc.(map[string]any)["createdAt"] != "2026-01-05T10:00:00Z" || len(doc.(map[string]any)["lines"].([]any)) != 2 {
		t.Fatalf("item: %d %s", rec.Code, rec.Body)
	}
	rec, doc = call(t, s, "POST", "/purchases", alice, `{"lines": [{"bookId": 1, "quantity": 0}, {"quantity": 1}]}`)
	msg := wantError(t, rec, doc, 422)
	if !strings.Contains(msg, "/lines/0/quantity") || !strings.Contains(msg, "/lines/1/bookId") {
		t.Fatalf("pointers: %s", msg)
	}
	rec, doc = call(t, s, "POST", "/purchases", alice, `{"lines": [{"bookId": 1, "quantity": 2}]}`)
	if rec.Code != 201 || rec.Header().Get("Location") == "" || !reflect.DeepEqual(doc.(map[string]any)["lines"], []any{map[string]any{"bookId": 1.0, "quantity": 2.0}}) {
		t.Fatalf("create: %d %s", rec.Code, rec.Body)
	}
}

func TestCSVErrorsKeepTheirStatus(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	rec, _ := call(t, s, "GET", "/reports/sales?from=2026-01-01&to=2026-01-31", nil, "")
	if rec.Code != 200 || rec.Body.String() != "title,quantity\nDune,3\nA Wizard of Earthsea,2\nKindred,1\nLilith's Brood,1\n" || rec.Header().Get("Content-Type") != "text/csv; charset=utf-8" {
		t.Fatalf("csv: %d %q", rec.Code, rec.Body)
	}
	for _, q := range []string{"", "from=2026-01-01", "from=2026-13-01&to=2026-01-31", "from=2026-02-01&to=2026-01-01"} {
		rec, doc := call(t, s, "GET", "/reports/sales?"+q, nil, "")
		wantError(t, rec, doc, 400)
	}
	rec, doc := call(t, s, "GET", "/reports/sales?from=2026-01-01&to=2026-01-31&boom=1", nil, "")
	if rec.Code != 400 && rec.Code != 500 {
		t.Fatalf("SQL error after headers: %d %s", rec.Code, rec.Body)
	}
	wantError(t, rec, doc, rec.Code)
	rec, _ = call(t, s, "GET", "/reports/sales?from=2030-01-01&to=2030-01-31", nil, "")
	if rec.Code != 200 || rec.Body.String() != "title,quantity\n" {
		t.Fatalf("empty csv: %d %q", rec.Code, rec.Body)
	}
}

func TestDefinitionErrorsStayProblems(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	s.Set.Broken = append(s.Set.Broken, resource.Broken{File: "X.json", Name: "X", Paths: []string{"/x"}, Errors: apierr.List{{Code: apierr.UnknownColumn, Message: "m"}}})
	rec, _ := call(t, s, "GET", "/x", nil, "")
	if rec.Code != 500 || rec.Header().Get("Content-Type") != "application/problem+json; charset=utf-8" {
		t.Fatalf("got %d %s", rec.Code, rec.Header())
	}
}

func TestOpenAPIDocumentsParametersOperationsAndShapes(t *testing.T) {
	dir := t.TempDir()
	for _, files := range []map[string]string{testdb.ShopFiles, shopExtra} {
		for n, c := range files {
			_ = os.WriteFile(filepath.Join(dir, n), []byte(c), 0o644)
		}
	}
	set, errs, _ := resource.Load(dir, script.NewEngine(time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	var doc map[string]any
	if err := json.Unmarshal([]byte(mustJSON(t, openAPIDoc(set, plainJSON))), &doc); err != nil {
		t.Fatal(err)
	}
	paths := doc["paths"].(map[string]any)
	names := func(path, method string) []string {
		op, _ := paths[path].(map[string]any)[method].(map[string]any)
		var out []string
		for _, p := range op["parameters"].([]any) {
			out = append(out, p.(map[string]any)["in"].(string)+":"+p.(map[string]any)["name"].(string))
		}
		return out
	}
	got := strings.Join(names("/books", "get"), " ")
	for _, want := range []string{"query:title", "query:priceCents[gte]", "query:publishedAt[strictly_before]", "query:order[publishedAt]", "query:itemsPerPage", "query:page"} {
		if !strings.Contains(got, want) {
			t.Errorf("GET /books misses %s: %s", want, got)
		}
	}
	if got := names("/purchases/{id}", "get"); !reflect.DeepEqual(got, []string{"path:id", "header:Customer-Id"}) {
		t.Errorf("item parameters: %v", got)
	}
	if paths["/authors/{id}"] == nil || paths["/authors"] == nil || paths["/reports/sales"] == nil {
		t.Fatalf("operations missing: %v", paths)
	}
	list := paths["/books"].(map[string]any)["get"].(map[string]any)["responses"].(map[string]any)["200"].(map[string]any)["content"].(map[string]any)["application/json"].(map[string]any)["schema"].(map[string]any)
	if _, ok := list["properties"].(map[string]any)["itemsPerPage"]; !ok {
		t.Fatalf("envelope not documented: %v", list)
	}
	errSchema := paths["/books"].(map[string]any)["get"].(map[string]any)["responses"].(map[string]any)["4XX"].(map[string]any)["content"].(map[string]any)
	if _, ok := errSchema["application/json"]; !ok {
		t.Fatalf("error shape not documented: %v", errSchema)
	}
}

func TestShapedErrorsKeepTeachingFields(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	rec, doc := call(t, s, "GET", "/books?itemsPerPage=0", nil, "")
	m := doc.(map[string]any)
	if hint, _ := m["hint"].(string); rec.Code != 400 || m["code"] != "invalid_parameter" || !strings.HasSuffix(hint, "see: caddy resource guide parameters") || m["pointer"] != "/parameters/itemsPerPage" || m["error"] == nil {
		t.Fatalf("got %s", rec.Body)
	}
	rec, doc = call(t, s, "POST", "/purchases", map[string]string{"Customer-Id": "1"}, `{"lines": [{"bookId": 1, "quantity": 0}, {"quantity": 1}]}`)
	errs, _ := doc.(map[string]any)["errors"].([]any)
	if rec.Code != 422 || len(errs) != 2 || errs[0].(map[string]any)["pointer"] != "/lines/0/quantity" || !strings.HasSuffix(errs[0].(map[string]any)["hint"].(string), "see: caddy resource guide commands") {
		t.Fatalf("got %s", rec.Body)
	}
	off := false
	strict := plainJSON
	strict.ErrorDetails = &off
	s, _ = shopServer(t, strict)
	rec, doc = call(t, s, "GET", "/books?itemsPerPage=0", nil, "")
	if m := doc.(map[string]any); rec.Code != 400 || len(m) != 1 || m["error"] == nil {
		t.Fatalf("details off: %s", rec.Body)
	}
}

func TestLookupMissHintsAtThePhase(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	book := s.Set.ByName("Book")
	set, errs, _ := resource.Load(writeFiles(t, map[string]string{"book.query.ts": strings.Replace(testdb.ShopFiles["book.query.ts"], ` OR ${ctx.phase === "lookup"}`, "", 1)}), script.NewEngine(time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	book.QueryMod = set.Defs[0].QueryMod
	rec, doc := call(t, s, "PATCH", "/books/3", nil, `{"stock": 1}`)
	wantError(t, rec, doc, 404)
	if hint, _ := doc.(map[string]any)["hint"].(string); !strings.Contains(hint, `ctx.phase === "lookup"`) {
		t.Fatalf("hint: %s", rec.Body)
	}
	rec, doc = call(t, s, "PATCH", "/books/999", nil, `{"stock": 1}`)
	if hint, _ := doc.(map[string]any)["hint"].(string); rec.Code != 404 || strings.Contains(hint, "ctx.phase") {
		t.Fatalf("absent row: %s", rec.Body)
	}
}

func writeFiles(t *testing.T, files map[string]string) string {
	t.Helper()
	dir := t.TempDir()
	files["Q.json"] = `{"name":"Q","path":"/q","query":"book.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`
	for n, c := range files {
		if err := os.WriteFile(filepath.Join(dir, n), []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return dir
}

func TestItemOperationWithoutIdentifierInOutputKeepsTheRealID(t *testing.T) {
	s, pool := shopServer(t, plainJSON)
	total := func(id int) int {
		var n int
		_ = pool.QueryRow(context.Background(), "SELECT total_cents FROM purchase WHERE id = $1", id).Scan(&n)
		return n
	}
	cases := []struct {
		method, path string
		id, status   int
		want         int
	}{
		{"POST", "/marks/1/post", 1, 200, 701},
		{"PUT", "/marks/2/put", 2, 200, 702},
		{"PATCH", "/marks/3", 3, 200, 702},
		{"DELETE", "/marks/4", 4, 204, 704},
	}
	for _, c := range cases {
		rec, doc := call(t, s, c.method, c.path, nil, "")
		if rec.Code != c.status || total(c.id) != c.want {
			t.Errorf("%s %s: status %d body %s total %d", c.method, c.path, rec.Code, rec.Body, total(c.id))
			continue
		}
		if c.status == 200 {
			m, _ := doc.(map[string]any)
			if _, has := m["id"]; has || m["totalCents"] != float64(c.want) {
				t.Errorf("%s %s: body %s", c.method, c.path, rec.Body)
			}
		}
	}
}

func TestNormalizeIDRefusesNil(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	d := s.Set.Defs[0]
	if _, err := normalizeID(d, nil); err == nil {
		t.Fatal("nil id must be an error")
	}
	if v, err := normalizeID(d, "7"); err != nil || v == nil {
		t.Fatalf("got %v %v", v, err)
	}
}

func TestHydraIdentityDoesNotDependOnOutput(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	hydra := map[string]string{"Accept": "application/ld+json"}
	rec, doc := call(t, s, "GET", "/marks/3", hydra, "")
	m, _ := doc.(map[string]any)
	if _, has := m["id"]; rec.Code != 200 || m["@id"] != "/marks/3" || has || m["totalCents"] != 3000.0 {
		t.Fatalf("item: %d %s", rec.Code, rec.Body)
	}
	rec, doc = call(t, s, "GET", "/marks?page=1", hydra, "")
	m, _ = doc.(map[string]any)
	members, _ := m["member"].([]any)
	if rec.Code != 200 || len(members) == 0 {
		t.Fatalf("collection: %d %s", rec.Code, rec.Body)
	}
	for _, x := range members {
		mm := x.(map[string]any)
		if _, has := mm["id"]; has || mm["@id"] == nil || mm["@id"] == "/marks/<nil>" {
			t.Fatalf("member: %v", mm)
		}
	}
	if members[0].(map[string]any)["@id"] != "/marks/1" {
		t.Fatalf("first member: %v", members[0])
	}
	rec, _ = call(t, s, "PATCH", "/marks/3", hydra, "")
	if !strings.Contains(rec.Body.String(), `"@id":"/marks/3"`) {
		t.Fatalf("patch: %d %s", rec.Code, rec.Body)
	}
}

func TestItemWriteCommandMayAnswerWithResponse(t *testing.T) {
	s, pool := shopServer(t, plainJSON)
	total := func(id int) int {
		var n int
		_ = pool.QueryRow(context.Background(), "SELECT total_cents FROM purchase WHERE id = $1", id).Scan(&n)
		return n
	}
	rec, _ := call(t, s, "POST", "/pages/1/publish", map[string]string{"Accept": "text/html"}, "")
	if rec.Code != 200 || !strings.HasPrefix(rec.Header().Get("Content-Type"), "text/html") || rec.Body.String() != "<h1>published</h1>" || total(1) != 705 {
		t.Fatalf("html: %d %q %s total %d", rec.Code, rec.Header().Get("Content-Type"), rec.Body, total(1))
	}
	rec, doc := call(t, s, "POST", "/pages/2/publish", map[string]string{"Accept": "application/json"}, "")
	if m, _ := doc.(map[string]any); rec.Code != 200 || m["totalCents"] != 705.0 || total(2) != 705 {
		t.Fatalf("json: %d %s", rec.Code, rec.Body)
	}
	rec, doc = call(t, s, "POST", "/pages/3/publish", map[string]string{"Accept": "image/png"}, "")
	wantError(t, rec, doc, 406)
	if total(3) != 705 {
		t.Fatalf("a refused read-back keeps the write: %d", total(3))
	}
	rec, doc = call(t, s, "POST", "/pages/999/publish", map[string]string{"Accept": "text/html"}, "")
	wantError(t, rec, doc, 404)
}

func TestMultiMethodQueryOperationServesEveryMethodAlike(t *testing.T) {
	s, pool := shopServer(t, plainJSON)
	total := func(id int) int {
		var n int
		_ = pool.QueryRow(context.Background(), "SELECT total_cents FROM purchase WHERE id = $1", id).Scan(&n)
		return n
	}
	for _, accept := range []string{"text/html", "application/json"} {
		get, _ := call(t, s, "GET", "/verify/1", map[string]string{"Accept": accept}, "")
		post, _ := call(t, s, "POST", "/verify/2", map[string]string{"Accept": accept}, `{"ignored": true}`)
		if get.Code != 200 || post.Code != 200 || get.Body.String() != strings.ReplaceAll(post.Body.String(), `"id":2`, `"id":1`) || get.Header().Get("Content-Type") != post.Header().Get("Content-Type") {
			t.Fatalf("%s: GET %d %q %q, POST %d %q %q", accept, get.Code, get.Header().Get("Content-Type"), get.Body, post.Code, post.Header().Get("Content-Type"), post.Body)
		}
		if accept == "text/html" && (get.Body.String() != "<h1>verified</h1>" || !strings.HasPrefix(get.Header().Get("Content-Type"), "text/html") || get.Header().Get("X-Method") != "GET" || post.Header().Get("X-Method") != "POST") {
			t.Fatalf("html: %q %v %v", get.Body, get.Header(), post.Header())
		}
		if accept == "application/json" && !strings.Contains(get.Body.String(), `"totalCents":900`) {
			t.Fatalf("json: %s", get.Body)
		}
	}
	if total(1) != 900 || total(2) != 900 {
		t.Fatalf("side effect: %d %d", total(1), total(2))
	}
	for _, method := range []string{"GET", "POST"} {
		rec, doc := call(t, s, method, "/verify/999", map[string]string{"Accept": "application/json"}, "")
		wantError(t, rec, doc, 404)
	}
	rec, doc := call(t, s, "POST", "/verify/1", map[string]string{"Accept": "image/png"}, "")
	wantError(t, rec, doc, 406)
}

func TestMultiMethodQueryOperationWithoutIdentifierReadsTheCollection(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	get, getDoc := call(t, s, "GET", "/searches?itemsPerPage=2", nil, "")
	post, postDoc := call(t, s, "POST", "/searches?itemsPerPage=2", nil, `{"ignored": true}`)
	if get.Code != 200 || post.Code != 200 || !reflect.DeepEqual(getDoc, postDoc) || len(ids(t, postDoc)) == 0 {
		t.Fatalf("GET %d %s, POST %d %s", get.Code, get.Body, post.Code, post.Body)
	}
}

func TestMultiMethodCommandOperationRunsTheWriteFlow(t *testing.T) {
	s, pool := shopServer(t, plainJSON)
	total := func(id int) int {
		var n int
		_ = pool.QueryRow(context.Background(), "SELECT total_cents FROM purchase WHERE id = $1", id).Scan(&n)
		return n
	}
	rec, doc := call(t, s, "POST", "/touch/1", nil, "")
	if m, _ := doc.(map[string]any); rec.Code != 200 || m["totalCents"] != 710.0 || total(1) != 710 {
		t.Fatalf("POST: %d %s", rec.Code, rec.Body)
	}
	rec, doc = call(t, s, "PUT", "/touch/2", nil, "")
	if m, _ := doc.(map[string]any); rec.Code != 200 || m["totalCents"] != 711.0 || total(2) != 711 {
		t.Fatalf("PUT: %d %s", rec.Code, rec.Body)
	}
	rec, doc = call(t, s, "PUT", "/touch/999", nil, "")
	wantError(t, rec, doc, 404)
	rec, _ = call(t, s, "GET", "/touch/1", nil, "")
	if rec.Code != 405 {
		t.Fatalf("GET is not listed: %d %s", rec.Code, rec.Body)
	}
}

func TestMultiMethodOperationAllowsEveryListedMethod(t *testing.T) {
	s, _ := shopServer(t, plainJSON)
	rec, _ := call(t, s, "DELETE", "/verify/1", nil, "")
	if rec.Code != 405 || rec.Header().Get("Allow") != "GET, POST" {
		t.Fatalf("%d Allow %q", rec.Code, rec.Header().Get("Allow"))
	}
	rec, _ = call(t, s, "PATCH", "/touch/1", nil, "")
	if rec.Code != 405 || rec.Header().Get("Allow") != "POST, PUT" {
		t.Fatalf("%d Allow %q", rec.Code, rec.Header().Get("Allow"))
	}
	rec, _ = call(t, s, "HEAD", "/verify/1", map[string]string{"Accept": "application/json"}, "")
	if rec.Code != 200 {
		t.Fatalf("HEAD: %d", rec.Code)
	}
}

func TestOpenAPIListsEveryMethodOfAMultiMethodOperation(t *testing.T) {
	dir := t.TempDir()
	for _, files := range []map[string]string{testdb.ShopFiles, shopExtra} {
		for n, c := range files {
			_ = os.WriteFile(filepath.Join(dir, n), []byte(c), 0o644)
		}
	}
	set, errs, _ := resource.Load(dir, script.NewEngine(time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	var doc map[string]any
	if err := json.Unmarshal([]byte(mustJSON(t, openAPIDoc(set, plainJSON))), &doc); err != nil {
		t.Fatal(err)
	}
	item := doc["paths"].(map[string]any)["/verify/{id}"].(map[string]any)
	get, post := item["get"].(map[string]any), item["post"].(map[string]any)
	if get == nil || post == nil || len(item) != 2 || get["operationId"] == post["operationId"] || get["operationId"] == nil || post["operationId"] == nil {
		t.Fatalf("operations: %v", item)
	}
	if post["requestBody"] != nil || get["requestBody"] != nil || post["summary"] != "One-click verification" {
		t.Fatalf("a read-flow operation takes no body: %v", post)
	}
	if !reflect.DeepEqual(get["responses"], post["responses"]) || !reflect.DeepEqual(get["parameters"], post["parameters"]) {
		t.Fatalf("same documentation expected:\n%v\n%v", get, post)
	}
	touch := doc["paths"].(map[string]any)["/touch/{id}"].(map[string]any)
	if touch["post"] == nil || touch["put"] == nil {
		t.Fatalf("command methods: %v", touch)
	}
}
