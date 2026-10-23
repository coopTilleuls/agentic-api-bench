package httpapi

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

var rawFiles = map[string]string{
	"Welcome.json": `{
  "name": "Welcome",
  "operations": [
    {"method": "GET", "path": "/welcome", "query": "welcome.query.ts"},
    {"method": "GET", "path": "/greetings/{id}", "query": "greeting.star"},
    {"method": "POST", "path": "/go", "command": "go.command.ts", "input": {}}
  ],
  "properties": {"id": {"type": "integer", "identifier": true}, "title": {"type": "string"}}
}`,
	"welcome.query.ts": "export default function query(ctx) {\n" +
		"  if (ctx.request.accepts(\"text/html\") && !ctx.request.accepts(\"application/json\")) {\n" +
		"    return ctx.response({status: 200, headers: {\"Content-Type\": \"text/html; charset=utf-8\"}, body: `<h1>Welcome</h1><p>${ctx.crypto.sha256(\"welcome\").slice(0, 8)}</p>`});\n" +
		"  }\n" +
		"  return {items: [{id: 1, title: \"Welcome\"}], totalItems: 1};\n" +
		"}",
	"greeting.star": "def query(ctx):\n" +
		"    if ctx.phase != \"read\":\n" +
		"        return None\n" +
		"    if ctx.id == 404:\n" +
		"        return ctx.response(status = 404, headers = {\"Content-Type\": \"text/html\"}, body = \"<h1>Nobody</h1>\")\n" +
		"    return ctx.response(headers = {\"Content-Type\": \"text/plain\"}, body = \"hello \" + str(ctx.id))\n",
	"go.command.ts": "export function create(input, ctx) {\n" +
		"  ctx.db.exec(ctx.sql`SELECT 1`);\n" +
		"  return ctx.response({status: 303, headers: {Location: \"/welcome\"}, body: \"\"});\n" +
		"}",
}

func rawServer(t *testing.T, files map[string]string) *Server {
	t.Helper()
	pool := testdb.Pool(t, testdb.Fixture)
	dir := t.TempDir()
	for n, c := range files {
		if err := os.WriteFile(filepath.Join(dir, n), []byte(c), 0o644); err != nil {
			t.Fatal(err)
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
	return &Server{Set: set, Runtime: rt, Output: Output{Formats: []string{"json"}}}
}

func TestRawHTMLResponseWithContentNegotiation(t *testing.T) {
	s := rawServer(t, rawFiles)
	srv := httptest.NewServer(s)
	defer srv.Close()
	get := func(path, accept string) (*http.Response, string) {
		t.Helper()
		req, _ := http.NewRequest(http.MethodGet, srv.URL+path, nil)
		if accept != "" {
			req.Header.Set("Accept", accept)
		}
		res, err := http.DefaultClient.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		defer res.Body.Close()
		b, err := io.ReadAll(res.Body)
		if err != nil {
			t.Fatalf("reading the body: %v", err)
		}
		return res, string(b)
	}

	res, body := get("/welcome", "text/html")
	if res.StatusCode != 200 || res.Header.Get("Content-Type") != "text/html; charset=utf-8" || body != "<h1>Welcome</h1><p>280d44ab</p>" {
		t.Fatalf("html: %d %s %q", res.StatusCode, res.Header, body)
	}
	res, body = get("/welcome", "application/json")
	if res.StatusCode != 200 || !strings.HasPrefix(res.Header.Get("Content-Type"), "application/json") || !strings.Contains(body, `"title":"Welcome"`) {
		t.Fatalf("json: %d %s %q", res.StatusCode, res.Header, body)
	}
	res, _ = get("/welcome", "application/xml")
	if res.StatusCode != 406 {
		t.Fatalf("an unserved type the script does not answer is still 406, got %d", res.StatusCode)
	}

	res, body = get("/greetings/7", "")
	if res.StatusCode != 200 || res.Header.Get("Content-Type") != "text/plain" || body != "hello 7" {
		t.Fatalf("item: %d %s %q", res.StatusCode, res.Header, body)
	}
	res, body = get("/greetings/404", "text/html")
	if res.StatusCode != 404 || body != "<h1>Nobody</h1>" {
		t.Fatalf("custom status on an item: %d %q", res.StatusCode, body)
	}

	res, body = get("/greetings/abc", "application/json")
	if res.StatusCode != 404 || res.ContentLength != int64(len(body)) {
		t.Fatalf("a framework 404 must frame its body: %d length %d, read %d bytes", res.StatusCode, res.ContentLength, len(body))
	}

	client := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	res, err := client.Post(srv.URL+"/go", "application/json", nil)
	if err != nil {
		t.Fatal(err)
	}
	res.Body.Close()
	if res.StatusCode != 303 || res.Header.Get("Location") != "/welcome" {
		t.Fatalf("redirect: %d %s", res.StatusCode, res.Header)
	}
}

func TestRawResponseOutsideAnOperationIsADefinitionError(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	dir := writeFiles(t, map[string]string{"book.query.ts": "export default function query(ctx) { return ctx.response({body: \"x\"}); }"})
	set, errs, _ := resource.Load(dir, script.NewEngine(5*time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	rt := &resource.Runtime{Pool: pool, Set: set}
	errs = resource.Check(context.Background(), rt, set)
	if len(errs) != 1 || errs[0].Code != apierr.ResponseNotAllowed {
		t.Fatalf("got %v", errs)
	}
}
