package app

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/mcp"
)

func get(a *App, target string) *httptest.ResponseRecorder {
	rec := httptest.NewRecorder()
	a.ServeHTTP(rec, httptest.NewRequest("GET", target, nil))
	return rec
}

func TestStartFailsOnlyOnConfigErrors(t *testing.T) {
	if _, err := Start(context.Background(), t.TempDir(), "", true); err == nil {
		t.Fatal("a missing dsn is a configuration error")
	}
	a, err := Start(context.Background(), filepath.Join(t.TempDir(), "missing"), "postgres://nobody@127.0.0.1:1/x", true)
	if err != nil {
		t.Fatalf("missing dir and unreachable database must not fail: %v", err)
	}
	defer a.Close()
	if rec := get(a, "/books"); rec.Code != 404 || !strings.Contains(rec.Body.String(), "dir_not_found") {
		t.Fatalf("unexpected %d %s", rec.Code, rec.Body)
	}
	rec := httptest.NewRecorder()
	a.ServeHTTP(rec, httptest.NewRequest("POST", "/mcp", strings.NewReader(`{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"validate"}}`)))
	if rec.Code != 200 || !strings.Contains(rec.Body.String(), "dir_not_found") {
		t.Fatalf("mcp must be up and report errors: %d %s", rec.Code, rec.Body)
	}
}

func TestHotReload(t *testing.T) {
	dsn := os.Getenv("LLMAPI_TEST_DSN")
	if dsn == "" {
		t.Skip("LLMAPI_TEST_DSN not set")
	}
	dir := t.TempDir()
	write := func(content string) {
		if err := os.WriteFile(filepath.Join(dir, "Thing.json"), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	write(`{"name":"Thing","path":"/things","query":"thing.query.ts","properties":{"id":{"type":"integer"}}}`)
	_ = os.WriteFile(filepath.Join(dir, "thing.query.ts"), []byte("export default (ctx) => ctx.sql`SELECT 1 AS id`;"), 0o644)
	a, err := Start(context.Background(), dir, dsn, true)
	if err != nil {
		t.Fatal(err)
	}
	defer a.Close()
	if rec := get(a, "/things"); rec.Code != 500 || !strings.Contains(rec.Body.String(), "missing_identifier") {
		t.Fatalf("broken definition must answer its errors: %d %s", rec.Code, rec.Body)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go a.Watch(ctx, 20*time.Millisecond)
	write(`{"name":"Thing","path":"/things","query":"thing.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`)
	deadline := time.Now().Add(3 * time.Second)
	for {
		rec := get(a, "/things")
		if rec.Code == http.StatusOK {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("not reloaded: %d %s", rec.Code, rec.Body)
		}
		time.Sleep(20 * time.Millisecond)
	}
	if r := a.Report(); !r.Valid {
		t.Fatalf("report not refreshed: %s", r.Error())
	}
	if names := a.Definitions(); len(names) != 1 {
		t.Fatalf("definitions not refreshed: %v", names)
	}
	write(`{"name":"Thing"`)
	report := a.Validate(context.Background(), mcp.ValidateArgs{})
	if report.Valid || report.Errors[0].Code != apierr.InvalidJSON {
		t.Fatalf("validate must report the current state: %s", report.Error())
	}
	if rec := get(a, "/things"); rec.Code == 200 {
		t.Fatal("validate also applies the current state")
	}
}

func TestDirFuncIsFollowedOnReload(t *testing.T) {
	first, second := t.TempDir(), t.TempDir()
	_ = os.WriteFile(filepath.Join(second, "Bad.json"), []byte(`{"name":`), 0o644)
	dir := first
	a, err := New(context.Background(), "", "postgres://nobody@127.0.0.1:1/x", true)
	if err != nil {
		t.Fatal(err)
	}
	defer a.Close()
	a.DirFunc = func() string { return dir }
	if r := a.Reload(context.Background()); hasCode(r, apierr.InvalidJSON) {
		t.Fatal("first dir has no definition")
	}
	dir = second
	if r := a.Reload(context.Background()); !hasCode(r, apierr.InvalidJSON) {
		t.Fatalf("second dir not followed: %s", r.Error())
	}
}

func hasCode(r apierr.Report, c apierr.Code) bool {
	for _, e := range r.Errors {
		if e.Code == c {
			return true
		}
	}
	return false
}
