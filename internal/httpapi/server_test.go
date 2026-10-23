package httpapi

import (
	"encoding/json"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

func newServer(t *testing.T) *Server {
	t.Helper()
	pool := testdb.Pool(t, testdb.Fixture)
	dir := t.TempDir()
	files := map[string]string{
		"Author.json": `{"name":"Author","path":"/authors","table":"author","properties":{"id":{"type":"integer","identifier":true},"name":{"type":"string","filterable":true}}}`,
		"Book.ts": `export default defineResource({ name: "Book", path: "/books", query: "book.query.ts", command: "book.command.ts", itemsPerPage: 2,
  input: { title: { type: "string", required: true }, author_id: { type: "integer", required: true }, published: { type: "boolean" } },
  properties: { id: { type: "integer", identifier: true }, title: { type: "string" }, author: { type: "string", filterable: true } } });`,
		"book.command.ts": "export function create(input, ctx) { const [r] = ctx.db.query(ctx.sql`INSERT INTO book (title, author_id, published) VALUES (${input.title}, ${input.author_id}, ${input.published ?? false}) RETURNING id`); return r.id; }\n" +
			"export function update(input, ctx) { if ('published' in input) ctx.db.exec(ctx.sql`UPDATE book SET published = ${input.published} WHERE id = ${ctx.id}`); }",
		"book.query.ts": "export default (ctx: QueryContext) => ctx.sql`SELECT b.id, b.title, a.name AS author FROM book b JOIN author a ON a.id = b.author_id WHERE b.published`;",
		"Report.json": `{"name":"AuthorReport","formats":["csv"],"operations":[
  {"method":"GET","path":"/business/export","format":"csv","query":"report.query.ts"},
  {"method":"GET","path":"/business/summary","format":"json","query":"report.query.ts"},
  {"method":"POST","path":"/business/rename","format":"json","command":"rename.command.ts"}],
  "properties":{"author":{"type":"string","filterable":true},"books":{"type":"integer"}}}`,
		"report.query.ts":   "export default (ctx) => ctx.sql`SELECT a.name AS author, count(b.id)::int AS books FROM author a LEFT JOIN book b ON b.author_id = a.id AND b.published GROUP BY a.name ORDER BY a.name`;",
		"rename.command.ts": "export function create(input, ctx) { if (input.books < 0) ctx.reject({message: 'negative', pointer: '/books'}); return { author: input.author.toUpperCase(), books: input.books }; }",
	}
	for n, c := range files {
		if err := os.WriteFile(filepath.Join(dir, n), []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	set, errs, _ := resource.Load(dir, script.NewGoja(time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	return &Server{Set: set, Runtime: &resource.Runtime{Pool: pool}}
}

func do(t *testing.T, s *Server, method, target string) (*httptest.ResponseRecorder, map[string]any) {
	t.Helper()
	rec := httptest.NewRecorder()
	s.ServeHTTP(rec, httptest.NewRequest(method, target, nil))
	var body map[string]any
	_ = json.Unmarshal(rec.Body.Bytes(), &body)
	return rec, body
}

func TestServeCollectionOverCustomQuery(t *testing.T) {
	s := newServer(t)
	rec, body := do(t, s, "GET", "/books")
	if rec.Code != 200 || !strings.HasPrefix(rec.Header().Get("Content-Type"), "application/ld+json") {
		t.Fatalf("unexpected %d %s", rec.Code, rec.Body)
	}
	if body["totalItems"] != 3.0 || len(body["member"].([]any)) != 2 {
		t.Fatalf("unexpected %s", rec.Body)
	}
	view := body["view"].(map[string]any)
	if view["next"] != "/books?page=2" {
		t.Fatalf("unexpected view %v", view)
	}
}

func TestServeFilteredPage(t *testing.T) {
	s := newServer(t)
	rec, body := do(t, s, "GET", "/books?author=Ursula+K.+Le+Guin&page=1")
	if rec.Code != 200 || body["totalItems"] != 2.0 {
		t.Fatalf("unexpected %d %s", rec.Code, rec.Body)
	}
}

func TestServeItem(t *testing.T) {
	s := newServer(t)
	rec, body := do(t, s, "GET", "/books/4")
	if rec.Code != 200 || body["@id"] != "/books/4" || body["author"] != "Frank Herbert" || body["@context"] != "/contexts/Book" {
		t.Fatalf("unexpected %d %s", rec.Code, rec.Body)
	}
	rec, body = do(t, s, "GET", "/books/3")
	if rec.Code != 404 || body["code"] != "not_found" || !strings.HasPrefix(rec.Header().Get("Content-Type"), "application/problem+json") {
		t.Fatalf("unpublished book: %d %s", rec.Code, rec.Body)
	}
	rec, _ = do(t, s, "GET", "/books/abc")
	if rec.Code != 404 {
		t.Fatalf("non integer id: %d %s", rec.Code, rec.Body)
	}
}

func TestServeErrors(t *testing.T) {
	s := newServer(t)
	cases := []struct {
		method, target string
		status         int
		code           string
	}{
		{"GET", "/books?title=Dune", 400, "filter_not_enabled"},
		{"GET", "/books?page=0", 400, "invalid_page"},
		{"GET", "/authors?id=x", 400, "filter_not_enabled"},
		{"GET", "/nope", 404, "not_found"},
		{"POST", "/books", 422, "required_property"},
		{"POST", "/books/1", 405, "method_not_allowed"},
	}
	for _, c := range cases {
		rec, body := do(t, s, c.method, c.target)
		if rec.Code != c.status || body["code"] != c.code || body["hint"] == nil {
			t.Errorf("%s %s: %d %s", c.method, c.target, rec.Code, rec.Body)
		}
	}
}

func TestServeUnknownQueryParamsAreIgnored(t *testing.T) {
	s := newServer(t)
	if rec, _ := do(t, s, "GET", "/books?tenant=x"); rec.Code != 200 {
		t.Fatalf("unexpected %d %s", rec.Code, rec.Body)
	}
}

func TestServeDiscovery(t *testing.T) {
	s := newServer(t)
	rec, body := do(t, s, "GET", "/")
	if rec.Code != 200 || body["book"] != "/books" || body["author"] != "/authors" {
		t.Fatalf("entrypoint: %d %s", rec.Code, rec.Body)
	}
	rec, body = do(t, s, "GET", "/contexts/Book")
	if rec.Code != 200 || body["@context"].(map[string]any)["title"] != "Book/title" {
		t.Fatalf("context: %d %s", rec.Code, rec.Body)
	}
	rec, body = do(t, s, "GET", "/openapi.json")
	if rec.Code != 200 || body["openapi"] != "3.1.0" {
		t.Fatalf("openapi: %d %s", rec.Code, rec.Body)
	}
	rec, _ = do(t, s, "GET", "/mcp")
	if rec.Code != 404 {
		t.Fatalf("mcp disabled must 404: %d", rec.Code)
	}
}

func TestDedicatedOperations(t *testing.T) {
	s := newServer(t)
	rec, _ := do(t, s, "GET", "/business/export")
	if rec.Code != 200 || rec.Body.String() != "author,books\nFrank Herbert,1\nUrsula K. Le Guin,2\n" || rec.Header().Get("Content-Disposition") != `attachment; filename="business-export.csv"` {
		t.Fatalf("export: %d %q %v", rec.Code, rec.Body, rec.Header())
	}
	req := httptest.NewRequest("GET", "/business/export?author=Frank+Herbert", nil)
	req.Header.Set("Accept", "application/ld+json")
	rec = httptest.NewRecorder()
	s.ServeHTTP(rec, req)
	if rec.Body.String() != "author,books\nFrank Herbert,1\n" {
		t.Fatalf("pinned format must ignore Accept: %q", rec.Body)
	}
	rec, _ = do(t, s, "GET", "/business/summary")
	if rec.Body.String() != `[{"author":"Frank Herbert","books":1},{"author":"Ursula K. Le Guin","books":2}]`+"\n" {
		t.Fatalf("summary: %q", rec.Body)
	}
	rec, _ = send(t, s, "POST", "/business/rename", "application/json", "", `{"author":"x","books":3}`)
	if rec.Code != 201 || rec.Body.String() != `{"author":"X","books":3}`+"\n" {
		t.Fatalf("command op: %d %q", rec.Code, rec.Body)
	}
	rec, body := send(t, s, "POST", "/business/rename", "application/json", "", `{"author":"x","books":-1}`)
	if rec.Code != 422 || body["pointer"] != "/books" {
		t.Fatalf("reject: %d %q", rec.Code, rec.Body)
	}
	rec, body = do(t, s, "DELETE", "/business/export")
	if rec.Code != 405 || body["code"] != "method_not_allowed" {
		t.Fatalf("405: %d %s", rec.Code, rec.Body)
	}
}
