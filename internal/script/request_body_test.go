package script

import (
	"net/http"
	"reflect"
	"strings"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
)

func bodyCall(body string) Call {
	return Call{Request: Request{Method: "POST", Header: http.Header{}, Body: body}}
}

func TestRequestBody(t *testing.T) {
	for _, body := range []string{"", `{"a": 1}`, "plain é text\n"} {
		v, err := evalPair(t, pair{`ctx.request.body`, `ctx.request.body`}, bodyCall(body))
		if err != nil || v != body {
			t.Errorf("body %q: got %#v, %v", body, v, err)
		}
	}
}

func TestRequestJSON(t *testing.T) {
	v, err := evalPair(t, pair{`ctx.request.json()`, `ctx.request.json()`}, bodyCall(`{"order-data": {"n": 2, "tags": ["a", null]}}`))
	want := map[string]any{"order-data": map[string]any{"n": int64(2), "tags": []any{"a", nil}}}
	if err != nil || !reflect.DeepEqual(v, want) {
		t.Fatalf("got %#v, %v", v, err)
	}
	v, err = evalPair(t, pair{`ctx.request.json()["a"]`, `ctx.request.json()["a"]`}, bodyCall(`{"a": "x"}`))
	if err != nil || v != "x" {
		t.Fatalf("got %#v, %v", v, err)
	}
}

func TestRequestJSONErrors(t *testing.T) {
	for _, body := range []string{"", "not json", `{"a":`} {
		_, err := evalPair(t, pair{`ctx.request.json()`, `ctx.request.json()`}, bodyCall(body))
		e, ok := apierr.As(err)
		if err == nil || !ok || e.Code != apierr.InvalidBody || !strings.Contains(e.Message, "ctx.request.json") || e.Hint == "" {
			t.Errorf("body %q: %#v", body, err)
		}
	}
}
