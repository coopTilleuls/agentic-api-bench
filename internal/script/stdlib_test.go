package script

import (
	"context"
	"net/http"
	"reflect"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
)

type pair struct{ ts, star string }

func evalPair(t *testing.T, p pair, c Call) (any, error) {
	t.Helper()
	engine := NewEngine(time.Second)
	call := func(file, src string) (any, error) {
		m, err := engine.Load(file, []byte(src))
		if err != nil {
			t.Fatalf("%s: %v", file, err)
		}
		return m.Call(context.Background(), "default", c)
	}
	tsV, tsErr := call("q.ts", "export default function query(ctx: any) { return "+p.ts+"; }")
	stV, stErr := call("q.star", "def query(ctx):\n    return "+p.star+"\n")
	if (tsErr == nil) != (stErr == nil) {
		t.Fatalf("engines disagree on %s: typescript err=%v, starlark err=%v", p.ts, tsErr, stErr)
	}
	if tsErr != nil {
		if code(t, tsErr) != code(t, stErr) {
			t.Fatalf("engines disagree on the error code: %v vs %v", tsErr, stErr)
		}
		return nil, tsErr
	}
	nondeterministic := regexp.MustCompile(`randomHex|uuid|now\(|unix\(`).MatchString(p.ts)
	if !nondeterministic && !reflect.DeepEqual(tsV, stV) {
		t.Fatalf("engines disagree on %s: typescript=%#v starlark=%#v", p.ts, tsV, stV)
	}
	return tsV, nil
}

func mustEval(t *testing.T, p pair) any {
	t.Helper()
	v, err := evalPair(t, p, Call{})
	if err != nil {
		t.Fatalf("%s: %v", p.ts, err)
	}
	return v
}

func TestCryptoHashes(t *testing.T) {
	for _, tc := range []struct {
		algo, name, want string
	}{
		{"sha256", "sha256", "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"},
		{"sha512", "sha512", "ddaf35a193617abacc417349ae20413112e6fa4e89a97ea20a9eeee64b55d39a2192992a274fc1a836ba3c23a3feebbd454d4423643ce80e2a9ac94fa54ca49f"},
		{"sha1", "sha1", "a9993e364706816aba3e25717850c26c9cd0d89d"},
	} {
		got := mustEval(t, pair{`ctx.crypto.` + tc.name + `("abc")`, `ctx.crypto.` + tc.name + `("abc")`})
		if got != tc.want {
			t.Errorf("%s: %v", tc.algo, got)
		}
	}
}

func TestCryptoHMACRFC4231(t *testing.T) {
	cases := []struct{ algo, key, data, want string }{
		{"sha256", strings.Repeat("\x0b", 20), "Hi There", "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7"},
		{"sha256", "Jefe", "what do ya want for nothing?", "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843"},
		{"sha512", "Jefe", "what do ya want for nothing?", "164b7a7bfcf819e2e395fbe73b56e0a387bd64222e831fd610270cd7ea2505549758bf75c05a994a6d034f65f8f0e6fdcaeab1a34d4a6b4b636e070a38bce737"},
		{"sha1", "Jefe", "what do ya want for nothing?", "effcdf6ae5eb2fa2d27416d5f184df9c259a7c79"},
	}
	for _, tc := range cases {
		expr := `ctx.crypto.hmac(` + quote(tc.algo) + `, ` + quote(tc.key) + `, ` + quote(tc.data) + `)`
		if got := mustEval(t, pair{expr, expr}); got != tc.want {
			t.Errorf("%s %q: %v", tc.algo, tc.data, got)
		}
	}
}

func quote(s string) string {
	var b strings.Builder
	b.WriteByte('"')
	for _, r := range s {
		switch {
		case r == '"' || r == '\\':
			b.WriteByte('\\')
			b.WriteRune(r)
		case r < 0x20:
			b.WriteString(`\x` + string("0123456789abcdef"[r>>4]) + string("0123456789abcdef"[r&15]))
		default:
			b.WriteRune(r)
		}
	}
	b.WriteByte('"')
	return b.String()
}

func TestCryptoEqual(t *testing.T) {
	for expr, want := range map[string]bool{
		`ctx.crypto.equal("abc", "abc")`: true,
		`ctx.crypto.equal("abc", "abd")`: false,
		`ctx.crypto.equal("abc", "ab")`:  false,
		`ctx.crypto.equal("", "")`:       true,
	} {
		if got := mustEval(t, pair{expr, expr}); got != want {
			t.Errorf("%s: %v", expr, got)
		}
	}
}

func TestCryptoRandomHexAndUUID(t *testing.T) {
	hex := regexp.MustCompile(`^[0-9a-f]{32}$`)
	a := mustEval(t, pair{`ctx.crypto.randomHex(16)`, `ctx.crypto.random_hex(16)`}).(string)
	if !hex.MatchString(a) {
		t.Fatalf("randomHex(16): %q", a)
	}
	if b := mustEval(t, pair{`ctx.crypto.randomHex(16)`, `ctx.crypto.randomHex(16)`}).(string); a == b {
		t.Fatal("randomHex repeats")
	}
	v4 := regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)
	if u := mustEval(t, pair{`ctx.crypto.uuid()`, `ctx.crypto.uuid()`}).(string); !v4.MatchString(u) {
		t.Fatalf("uuid: %q", u)
	}
}

func TestCryptoEncodings(t *testing.T) {
	for _, tc := range []struct {
		ts, star string
		want     any
	}{
		{`ctx.crypto.base64Encode("héllo?>")`, `ctx.crypto.base64_encode("héllo?>")`, "aMOpbGxvPz4="},
		{`ctx.crypto.base64Decode("aMOpbGxvPz4=")`, `ctx.crypto.base64_decode("aMOpbGxvPz4=")`, "héllo?>"},
		{`ctx.crypto.base64Decode("aMOpbGxvPz4")`, `ctx.crypto.base64Decode("aMOpbGxvPz4")`, "héllo?>"},
		{`ctx.crypto.hexEncode("hé")`, `ctx.crypto.hex_encode("hé")`, "68c3a9"},
		{`ctx.crypto.hexDecode("68c3a9")`, `ctx.crypto.hex_decode("68c3a9")`, "hé"},
		{`ctx.crypto.hexDecode("68C3A9")`, `ctx.crypto.hexDecode("68C3A9")`, "hé"},
	} {
		if got := mustEval(t, pair{tc.ts, tc.star}); got != tc.want {
			t.Errorf("%s: %#v, want %#v", tc.ts, got, tc.want)
		}
	}
}

func TestCryptoErrorsTeach(t *testing.T) {
	for _, tc := range []struct {
		expr string
		want apierr.Code
		in   string
	}{
		{`ctx.crypto.hmac("md5", "k", "d")`, apierr.InvalidCryptoCall, "sha256"},
		{`ctx.crypto.hexDecode("zz")`, apierr.InvalidCryptoCall, "hex"},
		{`ctx.crypto.hexDecode("abc")`, apierr.InvalidCryptoCall, "hex"},
		{`ctx.crypto.base64Decode("***")`, apierr.InvalidCryptoCall, "base64"},
		{`ctx.crypto.hexDecode("ff")`, apierr.InvalidCryptoCall, "UTF-8"},
		{`ctx.crypto.randomHex(0)`, apierr.InvalidCryptoCall, "1"},
		{`ctx.crypto.randomHex(100000)`, apierr.InvalidCryptoCall, "1024"},
	} {
		_, err := evalPair(t, pair{tc.expr, tc.expr}, Call{})
		if err == nil {
			t.Errorf("%s: no error", tc.expr)
			continue
		}
		e, _ := apierr.As(err)
		if e == nil || e.Code != tc.want || !strings.Contains(e.Message+e.Hint, tc.in) {
			t.Errorf("%s: %v", tc.expr, err)
		}
	}
}

func TestTime(t *testing.T) {
	iso := regexp.MustCompile(`^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$`)
	if s := mustEval(t, pair{`ctx.time.now()`, `ctx.time.now()`}).(string); !iso.MatchString(s) {
		t.Fatalf("now: %q", s)
	}
	u := mustEval(t, pair{`ctx.time.unix()`, `ctx.time.unix()`})
	var f float64
	switch x := u.(type) {
	case float64:
		f = x
	case int64:
		f = float64(x)
	}
	if d := f - float64(time.Now().Unix()); d < -2 || d > 2 {
		t.Fatalf("unix: %v", u)
	}
	for _, tc := range []struct {
		ts, star string
		want     any
	}{
		{`ctx.time.parse("2024-01-31T12:00:00Z")`, `ctx.time.parse("2024-01-31T12:00:00Z")`, int64(1706702400)},
		{`ctx.time.parse("2024-01-31T14:00:00+02:00")`, `ctx.time.parse("2024-01-31T14:00:00+02:00")`, int64(1706702400)},
		{`ctx.time.parse("2024-01-31")`, `ctx.time.parse("2024-01-31")`, int64(1706659200)},
		{`ctx.time.parse("2024-01-31T12:00:00.500Z")`, `ctx.time.parse("2024-01-31T12:00:00.500Z")`, 1706702400.5},
		{`ctx.time.format(1706702400)`, `ctx.time.format(1706702400)`, "2024-01-31T12:00:00.000Z"},
		{`ctx.time.format(1706702400.5)`, `ctx.time.format(1706702400.5)`, "2024-01-31T12:00:00.500Z"},
	} {
		got := mustEval(t, pair{tc.ts, tc.star})
		if g, ok := got.(float64); ok {
			if w, wok := tc.want.(float64); !wok || g != w {
				t.Errorf("%s: %#v", tc.ts, got)
			}
			continue
		}
		if got != tc.want {
			t.Errorf("%s: %#v, want %#v", tc.ts, got, tc.want)
		}
	}
	_, err := evalPair(t, pair{`ctx.time.parse("yesterday")`, `ctx.time.parse("yesterday")`}, Call{})
	if e, _ := apierr.As(err); e == nil || e.Code != apierr.InvalidTimeValue || !strings.Contains(e.Message, "ISO 8601") {
		t.Fatalf("parse error: %v", err)
	}
}

func TestRequestAccepts(t *testing.T) {
	for _, tc := range []struct {
		accept, typ string
		want        bool
	}{
		{"text/html,application/xhtml+xml,*/*;q=0.8", "text/html", true},
		{"application/json", "text/html", false},
		{"", "text/html", true},
		{"text/*", "text/html", true},
		{"*/*", "application/json", true},
		{"text/html;q=0", "text/html", false},
		{"text/*;q=0.5, text/html;q=0", "text/html", false},
		{"text/html;q=0, */*", "application/json", true},
		{"application/json;q=0.9, text/html", "text/html", true},
	} {
		c := Call{Request: Request{Header: http.Header{}}}
		if tc.accept != "" {
			c.Request.Header.Set("Accept", tc.accept)
		}
		for _, p := range []pair{{`ctx.request.accepts("` + tc.typ + `")`, `ctx.request.accepts("` + tc.typ + `")`}, {`ctx.request.accept === ` + quote(tc.accept) + ` && ctx.request.accepts("` + tc.typ + `")`, `ctx.request.accept == ` + quote(tc.accept) + ` and ctx.request.accepts("` + tc.typ + `")`}} {
			got, err := evalPair(t, p, c)
			if err != nil || got != tc.want {
				t.Errorf("Accept %q accepts(%q): %v %v, want %v", tc.accept, tc.typ, got, err, tc.want)
			}
		}
	}
}

func rawResponse(t *testing.T, p pair, c Call) *Response {
	t.Helper()
	v, err := evalPair(t, p, c)
	if err != nil {
		t.Fatal(err)
	}
	r, ok := v.(*Response)
	if !ok {
		t.Fatalf("got %#v, want *Response", v)
	}
	return r
}

func TestResponseValue(t *testing.T) {
	c := Call{RawAllowed: true}
	r := rawResponse(t, pair{
		`ctx.response({status: 302, headers: {Location: "/x"}, body: "<p>hi</p>"})`,
		`ctx.response(status = 302, headers = {"Location": "/x"}, body = "<p>hi</p>")`}, c)
	if r.Status != 302 || r.Headers["Location"] != "/x" || r.Body != "<p>hi</p>" {
		t.Fatalf("%+v", r)
	}
	r = rawResponse(t, pair{`ctx.response({body: "x"})`, `ctx.response({"body": "x"})`}, c)
	if r.Status != 200 || r.Body != "x" || len(r.Headers) != 0 {
		t.Fatalf("defaults: %+v", r)
	}
}

func TestResponseErrors(t *testing.T) {
	for _, tc := range []struct {
		name string
		p    pair
		call Call
		code apierr.Code
		in   string
	}{
		{"not allowed", pair{`ctx.response({body: "x"})`, `ctx.response({"body": "x"})`}, Call{}, apierr.ResponseNotAllowed, "operation"},
		{"bad status", pair{`ctx.response({status: 99, body: "x"})`, `ctx.response({"status": 99, "body": "x"})`}, Call{RawAllowed: true}, apierr.InvalidResponse, "status"},
		{"object body", pair{`ctx.response({body: {a: 1}})`, `ctx.response({"body": {"a": 1}})`}, Call{RawAllowed: true}, apierr.InvalidResponse, "string"},
		{"bad header", pair{`ctx.response({headers: {"X": "a\nb"}, body: ""})`, `ctx.response({"headers": {"X": "a\nb"}, "body": ""})`}, Call{RawAllowed: true}, apierr.InvalidResponse, "header"},
		{"unknown key", pair{`ctx.response({bodyy: "x"})`, `ctx.response({"bodyy": "x"})`}, Call{RawAllowed: true}, apierr.InvalidResponse, "body"},
	} {
		_, err := evalPair(t, tc.p, tc.call)
		e, _ := apierr.As(err)
		if e == nil || e.Code != tc.code || !strings.Contains(e.Message+e.Hint, tc.in) {
			t.Errorf("%s: %v", tc.name, err)
		}
	}
}
