package script

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"os"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

type evalHost struct{ env map[string]string }

func (evalHost) Query(context.Context, sqlf.Fragment) ([]map[string]any, error) {
	return nil, evalUnavailable("ctx.db", "there is no database in eval")
}

func (evalHost) Exec(context.Context, sqlf.Fragment) (int64, error) {
	return 0, evalUnavailable("ctx.db", "there is no database in eval")
}

func (evalHost) Fetch(context.Context, FetchRequest) (*FetchResponse, error) {
	return nil, evalUnavailable("ctx.fetch", "eval makes no network call")
}

func (h evalHost) Env(name string) (string, error) {
	if v, ok := h.env[name]; ok {
		return v, nil
	}
	return os.Getenv(name), nil
}

func evalUnavailable(what, why string) *apierr.Error {
	return &apierr.Error{Code: apierr.UsageError, Message: fmt.Sprintf("%s is not available in eval: %s", what, why), Hint: "eval only runs the offline stdlib (ctx.crypto, ctx.time, ctx.env, ctx.json). Use `caddy resource sql` for the database and `caddy resource request` for a whole request."}
}

// Eval runs one expression with the offline script stdlib and returns its
// value as JSON. lang is "ts" (default) or "starlark"; ctx.env reads env
// first, then the process environment.
func Eval(engine Engine, lang, expr string, env map[string]string) (string, error) {
	var file, src string
	switch lang {
	case "", "ts":
		file, src = "eval.ts", "export default function query(ctx: any) { return (\n"+expr+"\n); }"
	case "starlark", "star":
		file, src = "eval.star", "def query(ctx):\n    return (\n"+expr+"\n    )\n"
	default:
		return "", &apierr.Error{Code: apierr.UsageError, Message: fmt.Sprintf("unknown --lang %q", lang), Hint: "Use --lang ts (default) or --lang starlark."}
	}
	m, err := engine.Load(file, []byte(src))
	if err != nil {
		return "", err
	}
	v, err := m.Call(context.Background(), "default", Call{Request: Request{Header: http.Header{}}, Host: evalHost{env}, Operation: "eval"})
	if err != nil {
		return "", err
	}
	switch x := v.(type) {
	case sqlf.Fragment:
		return "", &apierr.Error{Code: apierr.UsageError, Message: "the expression returned a SQL fragment", Hint: "A ctx.sql value is only meaningful in a query; return plain data in eval."}
	case *Response:
		v = map[string]any{"status": x.Status, "headers": x.Headers, "body": x.Body}
	}
	b, err := json.Marshal(v)
	if err != nil {
		return "", &apierr.Error{Code: apierr.InternalError, Message: err.Error()}
	}
	return string(b), nil
}
