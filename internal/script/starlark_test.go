package script

import (
	"context"
	"net/http"
	"net/url"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

func star(t *testing.T, src string) (Module, error) {
	t.Helper()
	return NewEngine(200*time.Millisecond).Load("q.star", []byte(src))
}

func TestEngineDispatchesOnExtension(t *testing.T) {
	e := NewEngine(time.Second)
	if _, err := e.Load("q.py", []byte("def query(ctx):\n    return ctx.sql(\"SELECT 1\")\n")); err != nil {
		t.Fatalf(".py must load as Starlark: %v", err)
	}
	if _, err := e.Load("q.query.ts", []byte("export default (ctx) => ctx.sql`SELECT 1`;")); err != nil {
		t.Fatalf(".ts must load with goja: %v", err)
	}
}

func TestStarlarkExports(t *testing.T) {
	m, err := star(t, "def query(ctx):\n    pass\n\ndef create(input, ctx):\n    pass\n\ndef delete(input, ctx):\n    pass\n\nX = 1\n")
	if err != nil {
		t.Fatal(err)
	}
	if got := m.Exports(); !reflect.DeepEqual(got, []string{"create", "default", "delete"}) {
		t.Fatalf("unexpected %v", got)
	}
}

func TestStarlarkSQLPlaceholders(t *testing.T) {
	m, _ := star(t, `
def query(ctx):
    extra = ctx.sql("")
    if ctx.request.query("min") != None:
        extra = ctx.sql("AND b.year >= $1", int(ctx.request.query("min")))
    return ctx.sql("SELECT b.id FROM book b WHERE b.tenant = $1 $2 AND b.kind = $1", ctx.request.header("x-tenant"), extra)
`)
	v, err := m.Call(context.Background(), "default", Call{Request: Request{Query: url.Values{"min": {"1990"}}, Header: http.Header{"X-Tenant": {"acme"}}}})
	if err != nil {
		t.Fatal(err)
	}
	sql, args := v.(sqlf.Fragment).Render(0)
	if sql != "SELECT b.id FROM book b WHERE b.tenant = $1 AND b.year >= $2 AND b.kind = $3" || !reflect.DeepEqual(args, []any{"acme", int64(1990), "acme"}) {
		t.Fatalf("unexpected %q %#v", sql, args)
	}
}

func TestStarlarkSQLPlaceholderMismatch(t *testing.T) {
	m, _ := star(t, "def query(ctx):\n    return ctx.sql(\"SELECT $2\", 1)\n")
	_, err := m.Call(context.Background(), "default", Call{})
	if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), "$2") {
		t.Fatalf("unexpected %v", err)
	}
}

func TestStarlarkHostAPI(t *testing.T) {
	m, _ := star(t, `
def query(ctx):
    res = ctx.fetch("http://api/items", method = "POST", headers = {"authorization": "Bearer " + ctx.env("TOKEN")}, body = {"a": 1})
    rows = res.json()
    db = ctx.db.query(ctx.sql("SELECT 1"))
    n = ctx.db.exec(ctx.sql("SELECT 1")).row_count
    return {"items": rows[:ctx.items_per_page], "total_items": len(rows), "page": ctx.page, "filters": ctx.filters, "id": ctx.id, "op": ctx.operation, "status": res.status, "ok": res.ok, "db": db, "n": n, "j": ctx.json.decode(ctx.json.encode({"x": [1]}))}
`)
	h := &fakeHost{}
	v, err := m.Call(context.Background(), "default", Call{Host: h, Operation: "collection", Page: 2, ItemsPerPage: 1, Filters: map[string]any{"author": "x"}, ID: int64(7)})
	if err != nil {
		t.Fatal(err)
	}
	got := v.(map[string]any)
	want := map[string]any{"items": []any{map[string]any{"id": int64(1)}}, "total_items": int64(2), "page": int64(2), "filters": map[string]any{"author": "x"}, "id": int64(7), "op": "collection", "status": int64(200), "ok": true,
		"db": []any{map[string]any{"id": int64(1), "name": "x"}}, "n": int64(3), "j": map[string]any{"x": []any{int64(1)}}}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("got  %#v\nwant %#v", got, want)
	}
	if h.fetched[0].Method != "POST" || h.fetched[0].Headers["authorization"] != "Bearer s3cr3t" || h.fetched[0].Body != `{"a":1}` || h.fetched[0].Headers["content-type"] != "application/json" {
		t.Fatalf("unexpected fetch %+v", h.fetched)
	}
}

func TestStarlarkHostErrorsKeepCode(t *testing.T) {
	m, _ := star(t, "def query(ctx):\n    ctx.fetch(\"http://denied/\")\n")
	_, err := m.Call(context.Background(), "default", Call{Host: &fakeHost{}})
	if code(t, err) != apierr.FetchDenied {
		t.Fatalf("unexpected %v", err)
	}
}

func TestStarlarkReject(t *testing.T) {
	m, _ := star(t, "def create(input, ctx):\n    if input[\"title\"] == \"\":\n        ctx.reject(\"title is empty\", pointer = \"/title\", hint = \"send a title\")\n    return 1\n")
	_, err := m.Call(context.Background(), "create", Call{Host: &fakeHost{}, Input: map[string]any{"title": ""}})
	e, _ := apierr.As(err)
	if e == nil || e.Code != apierr.Rejected || e.Pointer != "/title" || e.Message != "title is empty" {
		t.Fatalf("unexpected %#v", err)
	}
	v, err := m.Call(context.Background(), "create", Call{Host: &fakeHost{}, Input: map[string]any{"title": "x"}})
	if err != nil || v != int64(1) {
		t.Fatalf("unexpected %v %v", v, err)
	}
}

func TestStarlarkTeachesPythonReflexes(t *testing.T) {
	cases := map[string]string{
		"import json\ndef query(ctx):\n    pass\n":                                       "ctx.json",
		"from x import y\ndef query(ctx):\n    pass\n":                                   "no import",
		"class Row:\n    pass\n":                                                         "no classes",
		"def query(ctx):\n    x = f\"a {ctx.id}\"\n":                                     "f-strings",
		"def query(ctx):\n    try:\n        pass\n    except Exception:\n        pass\n": "exceptions",
		"def query(ctx):\n    raise ValueError(\"x\")\n":                                 "ctx.reject",
		"def query(ctx):\n    if ctx.id is None:\n        pass\n":                        "== None",
		"async def query(ctx):\n    pass\n":                                              "async",
	}
	for src, want := range cases {
		_, err := star(t, src)
		if code(t, err) != apierr.TranspileError || !strings.Contains(err.Error(), want) || !strings.Contains(err.Error(), "q.star:") {
			t.Errorf("%q: %v", src, err)
		}
	}
}

func TestStarlarkRuntimeErrorAndTimeout(t *testing.T) {
	m, _ := star(t, "def query(ctx):\n    return {}[\"missing\"]\n")
	_, err := m.Call(context.Background(), "default", Call{})
	if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), "missing") {
		t.Fatalf("unexpected %v", err)
	}
	m, _ = star(t, "def query(ctx):\n    while True:\n        pass\n")
	_, err = m.Call(context.Background(), "default", Call{})
	if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), "time") {
		t.Fatalf("unexpected %v", err)
	}
}

func TestStarlarkMissingFunction(t *testing.T) {
	m, _ := star(t, "def create(input, ctx):\n    pass\n")
	_, err := m.Call(context.Background(), "default", Call{})
	if code(t, err) != apierr.InvalidExport || !strings.Contains(err.Error(), "def query(ctx)") {
		t.Fatalf("unexpected %v", err)
	}
}

func TestStarlarkSQLNamedPlaceholders(t *testing.T) {
	m, _ := star(t, `
def query(ctx):
    cond = ctx.sql("AND b.year >= {year}", year = 1990)
    return ctx.sql("SELECT '{{}}'::jsonb, b.id FROM book b WHERE b.author = {author} {cond} AND b.alt = {author}", author = "Le Guin", cond = cond)
`)
	v, err := m.Call(context.Background(), "default", Call{})
	if err != nil {
		t.Fatal(err)
	}
	sql, args := v.(sqlf.Fragment).Render(0)
	if sql != "SELECT '{}'::jsonb, b.id FROM book b WHERE b.author = $1 AND b.year >= $2 AND b.alt = $3" || !reflect.DeepEqual(args, []any{"Le Guin", int64(1990), "Le Guin"}) {
		t.Fatalf("unexpected %q %#v", sql, args)
	}
}

func TestStarlarkSQLNamedErrors(t *testing.T) {
	cases := map[string]string{
		`ctx.sql("SELECT {a}", b = 1)`:        "{a}",
		`ctx.sql("SELECT {a}", a = 1, b = 2)`: "b",
		`ctx.sql("SELECT $1 {a}", 1, a = 2)`:  "either",
	}
	for expr, want := range cases {
		m, _ := star(t, "def query(ctx):\n    return "+expr+"\n")
		_, err := m.Call(context.Background(), "default", Call{})
		if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), want) {
			t.Errorf("%s: %v", expr, err)
		}
	}
}
