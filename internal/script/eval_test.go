package script

import (
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
)

func TestEvalExpression(t *testing.T) {
	engine := NewEngine(time.Second)
	for _, tc := range []struct {
		lang, expr string
		env        map[string]string
		want       string
	}{
		{"ts", `ctx.crypto.hmac("sha256", "key", "1700000000" + "tok")`, nil, `"` + mustHMAC(t, "key", "1700000000tok") + `"`},
		{"starlark", `ctx.crypto.hmac("sha256", "key", "1700000000" + "tok")`, nil, `"` + mustHMAC(t, "key", "1700000000tok") + `"`},
		{"ts", `ctx.time.format(1700000000)`, nil, `"2023-11-14T22:13:20.000Z"`},
		{"", `[1, 2.5, "a", null, {k: true}]`, nil, `[1,2.5,"a",null,{"k":true}]`},
		{"starlark", `{"k": [1, None]}`, nil, `{"k":[1,null]}`},
		{"ts", `ctx.env("SECRET") + "!"`, map[string]string{"SECRET": "s3"}, `"s3!"`},
		{"starlark", `ctx.env("SECRET")`, map[string]string{"SECRET": "s3"}, `"s3"`},
		{"ts", `ctx.crypto.sha256("abc").slice(0, 8)`, nil, `"ba7816bf"`},
	} {
		got, err := Eval(engine, tc.lang, tc.expr, tc.env)
		if err != nil || got != tc.want {
			t.Errorf("%s %s: %q, %v, want %q", tc.lang, tc.expr, got, err, tc.want)
		}
	}
}

func mustHMAC(t *testing.T, key, data string) string {
	t.Helper()
	s, err := hmacHex("sha256", key, data)
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func TestEvalUnavailableAndErrors(t *testing.T) {
	engine := NewEngine(time.Second)
	for _, tc := range []struct{ lang, expr, want string }{
		{"ts", "ctx.db.query(ctx.sql`SELECT 1`)", "no database"},
		{"ts", `ctx.fetch("http://example.com")`, "network"},
		{"ts", "ctx.nope()", "nope"},
		{"starlark", "ctx.request.json()", "not JSON"},
		{"ts", "ctx.sql`SELECT 1`", "SQL fragment"},
		{"ruby", "1", "unknown --lang"},
	} {
		_, err := Eval(engine, tc.lang, tc.expr, nil)
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%s %s: %v, want %q", tc.lang, tc.expr, err, tc.want)
		}
		if _, ok := apierr.As(err); !ok {
			t.Errorf("%s: not an apierr: %T", tc.expr, err)
		}
	}
}
