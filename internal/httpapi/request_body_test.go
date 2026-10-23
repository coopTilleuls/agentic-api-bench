package httpapi

import (
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
)

var bodyFiles = map[string]string{
	"Hook.json": `{
  "name": "Hook",
  "unknownInput": "ignore",
  "operations": [
    {"method": "POST", "path": "/hooks/ts", "command": "ts.command.ts", "input": {}},
    {"method": "POST", "path": "/hooks/star", "command": "star_command.star", "input": {}},
    {"method": "POST", "path": "/hooks/typed", "command": "typed.command.ts", "input": {"title": {"type": "string"}}}
  ],
  "properties": {"id": {"type": "integer", "identifier": true}, "title": {"type": "string"}}
}`,
	"ts.command.ts": "export function create(input, ctx) {\n" +
		"  return ctx.response({body: ctx.request.body.length + \":\" + ctx.request.body});\n" +
		"}",
	"star_command.star": "def create(input, ctx):\n" +
		"    return ctx.response(body = str(len(ctx.request.body)) + \":\" + ctx.request.body)\n",
	"typed.command.ts": "export function create(input, ctx) {\n" +
		"  return ctx.response({body: ctx.request.json().extra + \"/\" + input.title + \"/\" + ctx.request.body.length});\n" +
		"}",
}

func post(t *testing.T, s *Server, path, body string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest(http.MethodPost, path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	s.ServeHTTP(rec, req)
	return rec
}

func TestRawBodyReadableWithEmptyInput(t *testing.T) {
	s := rawServer(t, bodyFiles)
	for _, path := range []string{"/hooks/ts", "/hooks/star"} {
		for _, body := range []string{"", `{"order-data": [1, 2]}`, "not json at all"} {
			rec := post(t, s, path, body)
			want := strconv.Itoa(len(body)) + ":" + body
			if rec.Code != 200 || rec.Body.String() != want {
				t.Errorf("%s %q: %d %q, want %q", path, body, rec.Code, rec.Body.String(), want)
			}
		}
	}
}

func TestRawBodyAlongsideValidatedInput(t *testing.T) {
	s := rawServer(t, bodyFiles)
	body := `{"title": "Dune", "extra": "x"}`
	rec := post(t, s, "/hooks/typed", body)
	if want := "x/Dune/" + strconv.Itoa(len(body)); rec.Code != 200 || rec.Body.String() != want {
		t.Fatalf("%d %q, want %q", rec.Code, rec.Body, want)
	}
}

func TestRawBodyHonorsSizeLimit(t *testing.T) {
	s := rawServer(t, bodyFiles)
	rec := post(t, s, "/hooks/ts", strings.Repeat("a", maxBody+100))
	if rec.Code != 200 || !strings.HasPrefix(rec.Body.String(), strconv.Itoa(maxBody)+":") {
		t.Fatalf("%d %.40s", rec.Code, rec.Body)
	}
}
