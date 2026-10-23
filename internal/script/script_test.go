package script

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

func engine() Engine { return NewGoja(200 * time.Millisecond) }

func load(t *testing.T, name string, src []byte) (Module, error) {
	t.Helper()
	return engine().Load(name, src)
}

func run(m Module, c Call) (sqlf.Fragment, error) {
	v, err := m.Call(context.Background(), "default", c)
	if err != nil {
		return sqlf.Fragment{}, err
	}
	f, ok := v.(sqlf.Fragment)
	if !ok {
		return sqlf.Fragment{}, &apierr.Error{Code: apierr.InvalidQueryResult, Message: fmt.Sprintf("%T", v)}
	}
	return f, nil
}

func code(t *testing.T, err error) apierr.Code {
	t.Helper()
	e, ok := apierr.As(err)
	if !ok {
		t.Fatalf("expected *apierr.Error, got %T %v", err, err)
	}
	return e.Code
}

func TestEvalDefinitionReturnsDefaultExportAsJSON(t *testing.T) {
	src := `interface X { a: number }
export default defineResource({ name: "Book", path: "/books", query: "book.query.ts", properties: { id: { type: "integer", identifier: true } } });`
	out, err := engine().EvalDefinition("Book.ts", []byte(src))
	if err != nil {
		t.Fatal(err)
	}
	var m map[string]any
	if err := json.Unmarshal(out, &m); err != nil {
		t.Fatal(err)
	}
	if m["name"] != "Book" || m["query"] != "book.query.ts" {
		t.Fatalf("unexpected %v", m)
	}
}

func TestEvalDefinitionAllowsImportFromHostModule(t *testing.T) {
	src := `import { defineResource } from "caddy-resource";
export default defineResource({ name: "A" });`
	if _, err := engine().EvalDefinition("A.ts", []byte(src)); err != nil {
		t.Fatal(err)
	}
}

func TestEvalDefinitionRejectsUnknownImport(t *testing.T) {
	_, err := engine().EvalDefinition("A.ts", []byte(`import pg from "pg"; export default defineResource({ name: String(pg) });`))
	if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), "pg") {
		t.Fatalf("unexpected %v", err)
	}
}

func TestEvalDefinitionTranspileErrorHasLocation(t *testing.T) {
	_, err := engine().EvalDefinition("Book.ts", []byte("export default defineResource({\n  name: \"Book\",,\n});"))
	if code(t, err) != apierr.TranspileError || !strings.Contains(err.Error(), "Book.ts:2:") {
		t.Fatalf("unexpected %v", err)
	}
}

func TestEvalDefinitionWithoutDefaultExport(t *testing.T) {
	_, err := engine().EvalDefinition("Book.ts", []byte(`export const book = defineResource({ name: "Book" });`))
	if code(t, err) != apierr.InvalidExport {
		t.Fatalf("unexpected %v", err)
	}
}

func TestQueryBuildsParameterizedFragmentFromRequest(t *testing.T) {
	src := `export default function query(ctx: QueryContext): Sql {
  const min = ctx.request.query("min");
  const extra = min ? ctx.sql` + "`AND b.year >= ${Number(min)}`" + ` : ctx.sql` + "``" + `;
  return ctx.sql` + "`SELECT b.id FROM book b WHERE b.tenant = ${ctx.request.header(\"X-Tenant\")} ${extra} AND ${ctx.request.method} = 'GET' AND ${ctx.request.path} <> ''`" + `;
}`
	q, err := load(t, "book.query.ts", []byte(src))
	if err != nil {
		t.Fatal(err)
	}
	req := Request{Method: "GET", Path: "/books", Query: url.Values{"min": {"1990"}}, Header: http.Header{"X-Tenant": {"acme"}}}
	frag, err := run(q, Call{Request: req})
	if err != nil {
		t.Fatal(err)
	}
	sql, args := frag.Render(0)
	if sql != "SELECT b.id FROM book b WHERE b.tenant = $1 AND b.year >= $2 AND $3 = 'GET' AND $4 <> ''" {
		t.Fatalf("unexpected sql %q", sql)
	}
	if !reflect.DeepEqual(args, []any{"acme", int64(1990), "GET", "/books"}) {
		t.Fatalf("unexpected args %#v", args)
	}
}

func TestQueryMissingRequestValuesAreNull(t *testing.T) {
	q, err := load(t, "q.ts", []byte("export default (ctx) => ctx.sql`SELECT ${ctx.request.query(\"nope\")}, ${ctx.request.header(\"nope\")}`;"))
	if err != nil {
		t.Fatal(err)
	}
	frag, err := run(q, Call{})
	if err != nil {
		t.Fatal(err)
	}
	_, args := frag.Render(0)
	if !reflect.DeepEqual(args, []any{nil, nil}) {
		t.Fatalf("unexpected args %#v", args)
	}
}

func TestQueryArraysBecomeTypedSlices(t *testing.T) {
	q, _ := load(t, "q.ts", []byte("export default (ctx) => ctx.sql`SELECT ${[1, 2]}, ${['a']}`;"))
	frag, err := run(q, Call{})
	if err != nil {
		t.Fatal(err)
	}
	_, args := frag.Render(0)
	if !reflect.DeepEqual(args, []any{[]int64{1, 2}, []string{"a"}}) {
		t.Fatalf("unexpected args %#v", args)
	}
}

func TestExportsListsFunctionsOnly(t *testing.T) {
	m, err := load(t, "q.ts", []byte("export default 'SELECT 1'; export function create() {}; export async function update() {}; export const x = 1;"))
	if err != nil {
		t.Fatal(err)
	}
	if got := m.Exports(); !reflect.DeepEqual(got, []string{"create", "update"}) {
		t.Fatalf("unexpected exports %v", got)
	}
}

func TestCallUnknownExport(t *testing.T) {
	m, _ := load(t, "q.ts", []byte("export function create() {}"))
	_, err := m.Call(context.Background(), "delete", Call{})
	if code(t, err) != apierr.InvalidExport {
		t.Fatalf("unexpected %v", err)
	}
}

func TestQueryExceptionIsScriptError(t *testing.T) {
	q, _ := load(t, "q.ts", []byte("export default () => { throw new Error('boom'); };"))
	_, err := run(q, Call{})
	if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), "boom") {
		t.Fatalf("unexpected %v", err)
	}
}

func TestQueryInfiniteLoopTimesOut(t *testing.T) {
	q, _ := load(t, "q.ts", []byte("export default () => { for(;;){} };"))
	_, err := run(q, Call{})
	if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), "time") {
		t.Fatalf("unexpected %v", err)
	}
}

func TestQueryRejectsObjectInterpolation(t *testing.T) {
	q, _ := load(t, "q.ts", []byte("export default (ctx) => ctx.sql`SELECT ${{a: 1}}`;"))
	_, err := run(q, Call{})
	if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), "interpolate") {
		t.Fatalf("unexpected %v", err)
	}
}

type fakeHost struct {
	queried  []string
	executed []string
	fetched  []FetchRequest
}

func (h *fakeHost) Query(_ context.Context, f sqlf.Fragment) ([]map[string]any, error) {
	sql, _ := f.Render(0)
	h.queried = append(h.queried, sql)
	return []map[string]any{{"id": int64(1), "name": "x"}}, nil
}

func (h *fakeHost) Exec(_ context.Context, f sqlf.Fragment) (int64, error) {
	sql, _ := f.Render(0)
	h.executed = append(h.executed, sql)
	return 3, nil
}

func (h *fakeHost) Fetch(_ context.Context, r FetchRequest) (*FetchResponse, error) {
	if r.URL == "http://denied/" {
		return nil, &apierr.Error{Code: apierr.FetchDenied, Message: "denied"}
	}
	h.fetched = append(h.fetched, r)
	return &FetchResponse{Status: 200, Headers: map[string]string{"content-type": "application/json"}, Body: []byte(`[{"id":1},{"id":2}]`)}, nil
}

func (h *fakeHost) Env(name string) (string, error) {
	if name == "TOKEN" {
		return "s3cr3t", nil
	}
	return "", &apierr.Error{Code: apierr.EnvDenied, Message: name}
}

func TestCallPassesContextAndReturnsPlainValues(t *testing.T) {
	m, _ := load(t, "q.ts", []byte(`export default async function (ctx) {
  const res = await ctx.fetch("http://api/items?x=1", { method: "POST", headers: { authorization: "Bearer " + ctx.env("TOKEN") }, body: JSON.stringify({ a: 1 }) });
  const rows = await res.json();
  const db = ctx.db.query(ctx.sql`+"`SELECT 1`"+`);
  return { items: rows.slice(0, ctx.itemsPerPage), totalItems: rows.length, page: ctx.page, filters: ctx.filters, id: ctx.id, op: ctx.operation, status: res.status, ok: res.ok, ct: res.headers["content-type"], db };
}`))
	h := &fakeHost{}
	v, err := m.Call(context.Background(), "default", Call{Host: h, Operation: "collection", Page: 2, ItemsPerPage: 1, Filters: map[string]any{"author": "x"}, ID: int64(7)})
	if err != nil {
		t.Fatal(err)
	}
	got := v.(map[string]any)
	if !reflect.DeepEqual(got["items"], []any{map[string]any{"id": int64(1)}}) || got["totalItems"] != int64(2) || got["page"] != int64(2) || got["id"] != int64(7) || got["op"] != "collection" || got["status"] != int64(200) || got["ok"] != true || got["ct"] != "application/json" {
		t.Fatalf("unexpected %#v", got)
	}
	if !reflect.DeepEqual(got["filters"], map[string]any{"author": "x"}) || !reflect.DeepEqual(got["db"], []any{map[string]any{"id": int64(1), "name": "x"}}) {
		t.Fatalf("unexpected %#v", got)
	}
	if len(h.fetched) != 1 || h.fetched[0].Method != "POST" || h.fetched[0].Headers["authorization"] != "Bearer s3cr3t" || h.fetched[0].Body != `{"a":1}` {
		t.Fatalf("unexpected fetch %+v", h.fetched)
	}
}

func TestHostErrorsKeepTheirCode(t *testing.T) {
	m, _ := load(t, "q.ts", []byte(`export default async (ctx) => { await ctx.fetch("http://denied/"); }`))
	_, err := m.Call(context.Background(), "default", Call{Host: &fakeHost{}})
	if code(t, err) != apierr.FetchDenied {
		t.Fatalf("unexpected %v", err)
	}
	m, _ = load(t, "q.ts", []byte(`export default (ctx) => ctx.env("DATABASE_URL")`))
	_, err = m.Call(context.Background(), "default", Call{Host: &fakeHost{}})
	if code(t, err) != apierr.EnvDenied {
		t.Fatalf("unexpected %v", err)
	}
}

func TestDbRequiresSqlFragment(t *testing.T) {
	m, _ := load(t, "q.ts", []byte(`export default (ctx) => ctx.db.exec("DELETE FROM book")`))
	_, err := m.Call(context.Background(), "default", Call{Host: &fakeHost{}})
	if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), "ctx.sql") {
		t.Fatalf("unexpected %v", err)
	}
}

func TestCommandReceivesInputAndExec(t *testing.T) {
	m, _ := load(t, "c.ts", []byte(`export function create(input, ctx) {
  const r = ctx.db.exec(ctx.sql`+"`INSERT INTO book (title) VALUES (${input.title})`"+`);
  return r.rowCount;
}`))
	h := &fakeHost{}
	v, err := m.Call(context.Background(), "create", Call{Host: h, Operation: "create", Input: map[string]any{"title": "Dune"}})
	if err != nil || v != int64(3) || len(h.executed) != 1 {
		t.Fatalf("unexpected %v %v %v", v, err, h.executed)
	}
}

func TestRejectBecomesStructuredError(t *testing.T) {
	m, _ := load(t, "c.ts", []byte(`export function create(input, ctx) {
  ctx.reject({ code: "author_without_name", message: "nope", pointer: "/author_id", hint: "name the author first", status: 409 });
}`))
	_, err := m.Call(context.Background(), "create", Call{Host: &fakeHost{}})
	e, ok := apierr.As(err)
	if !ok || e.Code != "author_without_name" || e.Pointer != "/author_id" || e.Hint != "name the author first" || e.Status() != 409 {
		t.Fatalf("unexpected %#v", err)
	}
	m, _ = load(t, "c.ts", []byte(`export function create(input, ctx) { ctx.reject("plain message"); }`))
	_, err = m.Call(context.Background(), "create", Call{Host: &fakeHost{}})
	e, _ = apierr.As(err)
	if e == nil || e.Code != apierr.Rejected || e.Message != "plain message" || e.Status() != 422 {
		t.Fatalf("unexpected %#v", err)
	}
}

func TestRejectedPromise(t *testing.T) {
	m, _ := load(t, "q.ts", []byte(`export default async () => { throw new Error("async boom"); }`))
	_, err := m.Call(context.Background(), "default", Call{})
	if code(t, err) != apierr.ScriptError || !strings.Contains(err.Error(), "async boom") {
		t.Fatalf("unexpected %v", err)
	}
}
