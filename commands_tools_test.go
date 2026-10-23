package llmapi

import (
	"bytes"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/spf13/cobra"

	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

func runSplit(t *testing.T, args ...string) (string, string, error) {
	t.Helper()
	cmd := &cobra.Command{Use: "resource", Long: resourceLong}
	configureResource(cmd)
	var out, errOut bytes.Buffer
	cmd.SetOut(&out)
	cmd.SetErr(&errOut)
	cmd.SetArgs(args)
	err := cmd.Execute()
	return out.String(), errOut.String(), err
}

func shopDSN(t *testing.T) string {
	t.Helper()
	pool := testdb.Pool(t, testdb.Fixture)
	var schema string
	if err := pool.QueryRow(context.Background(), "SELECT current_schema()").Scan(&schema); err != nil {
		t.Fatal(err)
	}
	dsn := os.Getenv("LLMAPI_TEST_DSN")
	sep := "?"
	if strings.Contains(dsn, "?") {
		sep = "&"
	}
	return dsn + sep + "search_path=" + schema
}

func writeFiles(t *testing.T, dir string, files map[string]string) {
	t.Helper()
	for name, content := range files {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
}

const authorDefinition = `{"name":"Author","path":"/authors","table":"author","properties":{"id":{"type":"integer","identifier":true},"name":{"type":"string"}}}`

func TestIntrospectCommand(t *testing.T) {
	dsn := shopDSN(t)
	out, _, err := runSplit(t, "introspect", "--dsn", dsn, "--rows", "1", "--table", "author")
	want := "author\n  id integer pk default nextval('author_id_seq'::regclass)\n  name text not null\n  rows:\n   id | name\n   1  | Ursula K. Le Guin\n"
	if err != nil || out != want {
		t.Fatalf("%v\n%s", err, out)
	}
	out, _, err = runSplit(t, "introspect", "--dsn", dsn, "--rows", "0")
	if err != nil || !strings.Contains(out, "\nbook\n") || strings.Contains(out, "rows:") {
		t.Fatalf("%v\n%s", err, out)
	}
	out, _, err = runSplit(t, "introspect", "--dsn", dsn, "--json", "--table", "book", "--rows", "1")
	var doc struct{ Tables []struct{ Name string } }
	if err != nil || json.Unmarshal([]byte(out), &doc) != nil || len(doc.Tables) != 1 || doc.Tables[0].Name != "book" {
		t.Fatalf("%v\n%s", err, out)
	}
	_, _, err = runSplit(t, "introspect", "--dsn", dsn, "--table", "nope")
	if err == nil || !strings.Contains(err.Error(), "see: caddy resource guide commands") {
		t.Fatalf("got %v", err)
	}
}

func TestToolsResolveTheDatabaseLikeTheModule(t *testing.T) {
	dsn := shopDSN(t)
	dir := t.TempDir()
	writeFiles(t, dir, map[string]string{"Caddyfile": ":8089 {\n\tresource {\n\t\tdsn " + dsn + "\n\t}\n}\n"})
	out, _, err := runSplit(t, "introspect", "--config", filepath.Join(dir, "Caddyfile"), "--table", "author", "--rows", "0")
	if err != nil || !strings.HasPrefix(out, "author\n") {
		t.Fatalf("--config: %v\n%s", err, out)
	}
	t.Chdir(dir)
	out, _, err = runSplit(t, "introspect", "--table", "author", "--rows", "0")
	if err != nil || !strings.HasPrefix(out, "author\n") {
		t.Fatalf("./Caddyfile: %v\n%s", err, out)
	}
	t.Setenv("DATABASE_URL", "")
	if err := os.Remove("Caddyfile"); err != nil {
		t.Fatal(err)
	}
	_, _, err = runSplit(t, "introspect")
	if err == nil || !strings.Contains(err.Error(), "no_dsn") || !strings.Contains(err.Error(), "see: caddy resource guide commands") {
		t.Fatalf("got %v", err)
	}
	t.Setenv("DATABASE_URL", dsn)
	if out, _, err = runSplit(t, "introspect", "--table", "author", "--rows", "0"); err != nil || !strings.HasPrefix(out, "author\n") {
		t.Fatalf("DATABASE_URL: %v\n%s", err, out)
	}
}

func TestSQLCommand(t *testing.T) {
	dsn := shopDSN(t)
	out, errOut, err := runSplit(t, "sql", "--dsn", dsn, "SELECT id, name FROM author WHERE id = $1", "--arg", "2")
	if err != nil || out != "id | name\n2  | Frank Herbert\n" || errOut != "rolled back (use --commit to keep changes)\n" {
		t.Fatalf("%v\n%q\n%q", err, out, errOut)
	}
	out, errOut, err = runSplit(t, "sql", "--dsn", dsn, "UPDATE author SET name = $1 WHERE id = $2", "--arg", "X", "--arg", "1")
	if err != nil || out != "1 rows affected\n" || !strings.Contains(errOut, "rolled back") {
		t.Fatalf("%v\n%q\n%q", err, out, errOut)
	}
	out, _, _ = runSplit(t, "sql", "--dsn", dsn, "SELECT name FROM author WHERE id = 1")
	if !strings.Contains(out, "Ursula") {
		t.Fatalf("not rolled back: %s", out)
	}
	_, errOut, err = runSplit(t, "sql", "--dsn", dsn, "--commit", "UPDATE author SET name = 'X' WHERE id = 1")
	if err != nil || strings.Contains(errOut, "rolled back") {
		t.Fatalf("%v %q", err, errOut)
	}
	out, _, _ = runSplit(t, "sql", "--dsn", dsn, "--json", "SELECT id, name FROM author WHERE id = 1")
	if out != "[\n  {\n    \"id\": 1,\n    \"name\": \"X\"\n  }\n]\n" {
		t.Fatalf("%q", out)
	}
	_, _, err = runSplit(t, "sql", "--dsn", dsn, "SELEC 1")
	if err == nil || !strings.Contains(err.Error(), "query_syntax_error") {
		t.Fatalf("got %v", err)
	}
	_, _, err = runSplit(t, "sql", "--dsn", dsn)
	if err == nil || !strings.Contains(err.Error(), "see: caddy resource guide commands") {
		t.Fatalf("missing query: %v", err)
	}
}

func TestRequestCommand(t *testing.T) {
	dsn := shopDSN(t)
	dir := t.TempDir()
	writeFiles(t, dir, map[string]string{"Author.json": authorDefinition})
	out, _, err := runSplit(t, "request", "GET", "/authors/1", "--dsn", dsn, "--dir", dir, "-H", "Accept: application/json")
	if err != nil || !strings.HasPrefix(out, "HTTP/1.1 200\nContent-Type: application/json; charset=utf-8\n") || !strings.HasSuffix(out, "\n\n{\n  \"id\": 1,\n  \"name\": \"Ursula K. Le Guin\"\n}\n") {
		t.Fatalf("%v\n%s", err, out)
	}
	out, errOut, err := runSplit(t, "request", "POST", "/authors", "--dsn", dsn, "--dir", dir, "-H", "Accept: application/json", "-d", `{"name":"Octavia Butler"}`)
	if err != nil || !strings.HasPrefix(out, "HTTP/1.1 201\n") || !strings.Contains(out, "Location: /authors/3\n") || !strings.Contains(out, `"name": "Octavia Butler"`) || !strings.Contains(errOut, "rolled back") {
		t.Fatalf("%v\n%s\n%s", err, out, errOut)
	}
	body := filepath.Join(t.TempDir(), "body.json")
	writeFiles(t, filepath.Dir(body), map[string]string{"body.json": `{"name":"Octavia Butler"}`})
	out, _, err = runSplit(t, "request", "POST", "/authors", "--dsn", dsn, "--dir", dir, "-d", "@"+body, "--commit")
	if err != nil || !strings.HasPrefix(out, "HTTP/1.1 201\n") {
		t.Fatalf("%v\n%s", err, out)
	}
	out, _, _ = runSplit(t, "sql", "--dsn", dsn, "SELECT count(*) FROM author")
	if out != "count\n3\n" {
		t.Fatalf("only the committed request persists: %q", out)
	}
	out, _, err = runSplit(t, "request", "GET", "/nope", "--dsn", dsn, "--dir", dir)
	if err != nil || !strings.HasPrefix(out, "HTTP/1.1 404\n") || !strings.Contains(out, "not_found") {
		t.Fatalf("a 4xx is not a failure: %v\n%s", err, out)
	}
	_, _, err = runSplit(t, "request", "GET", "/authors", "--dsn", dsn, "--dir", dir, "-H", "no colon")
	if err == nil || !strings.Contains(err.Error(), "see: caddy resource guide commands") {
		t.Fatalf("malformed header: %v", err)
	}
}

func TestRequestCommandFailsOnInvalidDefinitions(t *testing.T) {
	dsn := shopDSN(t)
	dir := t.TempDir()
	writeFiles(t, dir, map[string]string{"Author.json": `{"name":"Author","path":"/authors","table":"author","properties":{"id":{"type":"integer"}}}`})
	out, _, err := runSplit(t, "request", "GET", "/authors", "--dsn", dsn, "--dir", dir)
	if err == nil || !strings.Contains(out, `"valid": false`) || !strings.Contains(out, "missing_identifier") || strings.Contains(out, "HTTP/1.1") {
		t.Fatalf("%v\n%s", err, out)
	}
}

func TestEvalCommand(t *testing.T) {
	out, _, err := runSplit(t, "eval", `ctx.crypto.hmac("sha256", "key", "1700000000" + "tok")`)
	if err != nil || out != `"034f80794eb775260bdd55684adc5dd7e94045a10ad8ba1e5c65df9d037d0790"`+"\n" {
		t.Fatalf("%v %q", err, out)
	}
	out, _, err = runSplit(t, "eval", "--lang", "starlark", "--env", "SECRET=s3", `{"v": ctx.env("SECRET")}`)
	if err != nil || out != "{\"v\":\"s3\"}\n" {
		t.Fatalf("%v %q", err, out)
	}
	if _, _, err = runSplit(t, "eval", "--env", "novalue", "1"); err == nil || !strings.Contains(err.Error(), "see: caddy resource guide commands") {
		t.Fatalf("got %v", err)
	}
	if _, _, err = runSplit(t, "eval", "ctx.crypto.hmac('md5', 'k', 'd')"); err == nil || !strings.Contains(err.Error(), "invalid_crypto_call") {
		t.Fatalf("got %v", err)
	}
}

func TestGuideCommandSignature(t *testing.T) {
	cmd := guideCommand()
	if cmd.Use != "guide [topic]" || cmd.Short != "Print the core guide (read this first) or one topic" {
		t.Fatalf("%q %q", cmd.Use, cmd.Short)
	}
}
