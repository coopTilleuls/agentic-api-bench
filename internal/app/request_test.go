package app

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/httpapi"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

const authorDef = `{"name":"Author","path":"/authors","table":"author","properties":{"id":{"type":"integer","identifier":true},"name":{"type":"string"}}}`

func writeDir(t *testing.T, files map[string]string) string {
	t.Helper()
	dir := t.TempDir()
	for name, content := range files {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return dir
}

func TestRequestRollsBackUnlessCommitted(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	dir := writeDir(t, map[string]string{"Author.json": authorDef})
	ctx := context.Background()
	count := func() int {
		var n int
		if err := pool.QueryRow(ctx, "SELECT count(*) FROM author").Scan(&n); err != nil {
			t.Fatal(err)
		}
		return n
	}
	post := func(commit bool) *Response {
		req := httptest.NewRequest(http.MethodPost, "/authors", strings.NewReader(`{"name":"Octavia Butler"}`))
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("Accept", "application/json")
		res, report, err := Request(ctx, dir, pool, httpapi.Output{}, req, commit)
		if err != nil || !report.Valid {
			t.Fatalf("%v %s", err, report.Error())
		}
		return res
	}
	res := post(false)
	if res.Status != 201 || !strings.Contains(string(res.Body), `"Octavia Butler"`) {
		t.Fatalf("%d %s", res.Status, res.Body)
	}
	if res.Header.Get("Location") != "/authors/3" || count() != 2 {
		t.Fatalf("rolled back request: location %q, %d authors", res.Header.Get("Location"), count())
	}
	if post(true); count() != 3 {
		t.Fatalf("committed request: %d authors", count())
	}
}

func TestRequestReportsInvalidDefinitionsWithoutServing(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	dir := writeDir(t, map[string]string{"Author.json": `{"name":"Author","path":"/authors","table":"author","properties":{"id":{"type":"integer"}}}`})
	res, report, err := Request(context.Background(), dir, pool, httpapi.Output{}, httptest.NewRequest(http.MethodGet, "/authors", nil), false)
	if err != nil || res != nil || report.Valid || report.Errors[0].Code != apierr.MissingIdentifier {
		t.Fatalf("%+v %v %s", res, err, report.Error())
	}
}

func TestRequestAnswersFrameworkErrorsAsResponses(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	dir := writeDir(t, map[string]string{"Author.json": authorDef})
	res, report, err := Request(context.Background(), dir, pool, httpapi.Output{}, httptest.NewRequest(http.MethodGet, "/nope", nil), false)
	if err != nil || !report.Valid || res.Status != 404 || !strings.Contains(string(res.Body), "not_found") {
		t.Fatalf("%+v %v", res, err)
	}
}

func TestRequestScriptsSeeTheBodyAndTheTransaction(t *testing.T) {
	pool := testdb.Pool(t, testdb.Fixture)
	dir := writeDir(t, map[string]string{
		"Hook.json": `{"name":"Hook","operations":[{"method":"POST","path":"/hook","command":"hook.command.ts","input":{}}],"properties":{"id":{"type":"integer","identifier":true}}}`,
		"hook.command.ts": "export function create(input, ctx) {\n" +
			"  const [row] = ctx.db.query(ctx.sql`INSERT INTO author (name) VALUES (${ctx.request.json().name}) RETURNING id`);\n" +
			"  const [n] = ctx.db.query(ctx.sql`SELECT count(*)::int AS n FROM author`);\n" +
			"  return ctx.response({body: row.id + \"/\" + n.n});\n" +
			"}",
	})
	req := httptest.NewRequest(http.MethodPost, "/hook", strings.NewReader(`{"name":"x"}`))
	res, report, err := Request(context.Background(), dir, pool, httpapi.Output{}, req, false)
	if err != nil || !report.Valid || res.Status != 200 || string(res.Body) != "3/3" {
		t.Fatalf("%+v %v %s", res, err, report.Error())
	}
	var n int
	_ = pool.QueryRow(context.Background(), "SELECT count(*) FROM author").Scan(&n)
	if n != 2 {
		t.Fatalf("%d authors after rollback", n)
	}
}

func TestRequestFailedStatementDoesNotPoisonTheRequest(t *testing.T) {
	pool := testdb.Pool(t, `CREATE TABLE tag (id serial PRIMARY KEY, name text UNIQUE); INSERT INTO tag (name) VALUES ('a');`)
	dir := writeDir(t, map[string]string{
		"Hook.json": `{"name":"Hook","operations":[{"method":"POST","path":"/hook","command":"hook.command.ts","input":{}},{"method":"POST","path":"/hook2","command":"hook2.command.ts","input":{}}],"properties":{"id":{"type":"integer","identifier":true}}}`,
		"hook.command.ts": "export function create(input, ctx) {\n" +
			"  let failed = false;\n" +
			"  try { ctx.db.exec(ctx.sql`INSERT INTO tag (name) VALUES ('a')`); } catch (e) { failed = true; }\n" +
			"  const [n] = ctx.db.query(ctx.sql`SELECT count(*)::int AS n FROM tag`);\n" +
			"  return ctx.response({status: failed ? 409 : 201, body: String(n.n)});\n" +
			"}",
		"hook2.command.ts": "export function create(input, ctx) {\n" +
			"  let failed = false;\n" +
			"  try { ctx.db.query(ctx.sql`SELECT * FROM missing_table`); } catch (e) { failed = true; }\n" +
			"  const [n] = ctx.db.query(ctx.sql`SELECT count(*)::int AS n FROM tag`);\n" +
			"  return ctx.response({status: failed ? 409 : 201, body: String(n.n)});\n" +
			"}",
	})
	for _, path := range []string{"/hook", "/hook2"} {
		res, report, err := Request(context.Background(), dir, pool, httpapi.Output{}, httptest.NewRequest(http.MethodPost, path, nil), false)
		if err != nil || res == nil || res.Status != 409 || string(res.Body) != "1" {
			t.Fatalf("%s: %+v %v %s", path, res, err, report.Error())
		}
	}
}
