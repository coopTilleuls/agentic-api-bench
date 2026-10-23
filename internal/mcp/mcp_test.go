package mcp

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

type fakeBackend struct {
	lastBody     map[string]any
	lastOp       string
	lastID       string
	lastMethod   string
	lastValidate ValidateArgs
	lastHeader   http.Header
	lastParams   url.Values
}

func (f *fakeBackend) Guide(topic string) (string, error) {
	switch topic {
	case "":
		return "THE GUIDE", nil
	case "parameters":
		return "THE PARAMETERS TOPIC", nil
	}
	return "", errors.New(`unknown topic "` + topic + `"; topics: parameters, all`)
}
func (f *fakeBackend) Definitions() []*resource.Definition {
	return []*resource.Definition{{Name: "AuthorReport", Ops: []resource.Operation{{Method: "GET", Path: "/business/export", Format: "csv"}}, Properties: []resource.Property{{Name: "author", Type: "string", Filterable: true}}}, {Name: "BookReview", Path: "/book-reviews", Table: "review", Properties: []resource.Property{
		{Name: "id", Type: "integer", Identifier: true}, {Name: "stars", Type: "integer", Filterable: true, Required: true}, {Name: "body", Type: "string"}, {Name: "book", Type: "string", ReadOnly: true},
	}}}
}
func (f *fakeBackend) Validate(_ context.Context, a ValidateArgs) apierr.Report {
	f.lastValidate = a
	return apierr.NewReport(apierr.List{{Code: apierr.MissingIdentifier, Message: "m"}}, nil)
}
func (f *fakeBackend) List(_ context.Context, d *resource.Definition, req script.Request, params url.Values) (any, error) {
	f.lastHeader, f.lastParams = req.Header, params
	return map[string]any{"@id": d.Path, "totalItems": 0}, nil
}
func (f *fakeBackend) Get(_ context.Context, d *resource.Definition, _ script.Request, id string) (any, error) {
	return nil, &apierr.Error{Code: apierr.NotFound, Message: "BookReview " + id + " not found"}
}

func (f *fakeBackend) Create(_ context.Context, d *resource.Definition, _ script.Request, body map[string]any) (any, error) {
	f.lastBody = body
	return map[string]any{"@id": d.Path + "/1"}, nil
}
func (f *fakeBackend) Update(_ context.Context, d *resource.Definition, op string, req script.Request, id string, body map[string]any) (any, error) {
	f.lastOp, f.lastID, f.lastBody, f.lastMethod = op, id, body, req.Method
	if op == "delete" {
		return nil, nil
	}
	return map[string]any{"@id": d.Path + "/" + id}, nil
}

func (f *fakeBackend) Operation(_ context.Context, d *resource.Definition, i int, req script.Request, _ string, body map[string]any) (string, error) {
	f.lastParams, f.lastBody = req.Query, body
	return "author,books\nX,1\n", nil
}

func call(t *testing.T, s *Server, body string, header http.Header) (*httptest.ResponseRecorder, map[string]any) {
	t.Helper()
	req := httptest.NewRequest("POST", "/mcp", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json, text/event-stream")
	for k, v := range header {
		req.Header[k] = v
	}
	rec := httptest.NewRecorder()
	s.ServeHTTP(rec, req)
	var out map[string]any
	_ = json.Unmarshal(rec.Body.Bytes(), &out)
	return rec, out
}

func TestInitialize(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}, PerResourceTools: true}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}`, nil)
	res := out["result"].(map[string]any)
	if res["protocolVersion"] != "2025-06-18" || res["capabilities"].(map[string]any)["tools"] == nil || !strings.Contains(res["instructions"].(string), "framework_guide") {
		t.Fatalf("unexpected %v", out)
	}
}

func TestNotificationIsAccepted(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}}
	rec, _ := call(t, s, `{"jsonrpc":"2.0","method":"notifications/initialized"}`, nil)
	if rec.Code != http.StatusAccepted || rec.Body.Len() != 0 {
		t.Fatalf("unexpected %d %q", rec.Code, rec.Body)
	}
}

func TestToolsList(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}, PerResourceTools: true}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":2,"method":"tools/list"}`, nil)
	tools := out["result"].(map[string]any)["tools"].([]any)
	names := map[string]map[string]any{}
	for _, tl := range tools {
		m := tl.(map[string]any)
		names[m["name"].(string)] = m
	}
	for _, n := range []string{"framework_guide", "validate", "list_resources", "list_book_review", "get_book_review", "create_book_review", "update_book_review", "delete_book_review"} {
		if names[n] == nil {
			t.Fatalf("missing tool %s in %v", n, names)
		}
	}
	props := names["list_book_review"]["inputSchema"].(map[string]any)["properties"].(map[string]any)
	if props["stars"].(map[string]any)["type"] != "integer" || props["page"] == nil || props["body"] != nil {
		t.Fatalf("unexpected list schema %v", props)
	}
}

func TestToolsListWithoutPerResourceTools(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":2,"method":"tools/list"}`, nil)
	if n := len(out["result"].(map[string]any)["tools"].([]any)); n != 3 {
		t.Fatalf("expected 3 static tools, got %d", n)
	}
}

func toolText(t *testing.T, out map[string]any) (string, bool) {
	t.Helper()
	res, ok := out["result"].(map[string]any)
	if !ok {
		t.Fatalf("no result: %v", out)
	}
	content := res["content"].([]any)
	isErr, _ := res["isError"].(bool)
	return content[0].(map[string]any)["text"].(string), isErr
}

func TestCallGuide(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"framework_guide","arguments":{}}}`, nil)
	if text, isErr := toolText(t, out); text != "THE GUIDE" || isErr {
		t.Fatalf("unexpected %v", out)
	}
}

func TestCallGuideTopic(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"framework_guide","arguments":{"topic":"parameters"}}}`, nil)
	if text, isErr := toolText(t, out); text != "THE PARAMETERS TOPIC" || isErr {
		t.Fatalf("unexpected %v", out)
	}
	_, out = call(t, s, `{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"framework_guide","arguments":{"topic":"nope"}}}`, nil)
	if text, isErr := toolText(t, out); !isErr || !strings.Contains(text, "topics: parameters") {
		t.Fatalf("unexpected %v", out)
	}
}

func TestGuideToolListsTopics(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":2,"method":"tools/list"}`, nil)
	for _, tl := range out["result"].(map[string]any)["tools"].([]any) {
		m := tl.(map[string]any)
		if m["name"] != "framework_guide" {
			continue
		}
		desc := m["description"].(string)
		for _, topic := range []string{"definitions", "queries", "parameters", "commands", "operations", "formats", "scripts", "errors", "mcp", "schema"} {
			if !strings.Contains(desc, topic) {
				t.Errorf("description misses topic %s: %s", topic, desc)
			}
		}
		topic, _ := m["inputSchema"].(map[string]any)["properties"].(map[string]any)["topic"].(map[string]any)
		if topic == nil {
			t.Fatalf("no topic argument: %v", m["inputSchema"])
		}
		if strings.Contains(desc, "everything") || strings.Contains(topic["description"].(string), "all") || topic["type"] != "string" {
			t.Errorf("the guide tool takes one topic per call, never all: %v / %s", topic, desc)
		}
		return
	}
	t.Fatal("no framework_guide tool")
}

func TestCallValidateInline(t *testing.T) {
	b := &fakeBackend{}
	s := &Server{Backend: b}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"validate","arguments":{"definition":"{}","filename":"Book.json","query":"x"}}}`, nil)
	text, isErr := toolText(t, out)
	if !isErr || !strings.Contains(text, "missing_identifier") || b.lastValidate.Definition != "{}" || b.lastValidate.Query != "x" {
		t.Fatalf("unexpected %v %+v", out, b.lastValidate)
	}
}

func TestCallListForwardsArgumentsAndHeaders(t *testing.T) {
	b := &fakeBackend{}
	s := &Server{Backend: b, PerResourceTools: true}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"list_book_review","arguments":{"stars":5,"page":2}}}`, http.Header{"X-Tenant": {"acme"}})
	if _, isErr := toolText(t, out); isErr || b.lastParams.Get("stars") != "5" || b.lastParams.Get("page") != "2" || b.lastHeader.Get("X-Tenant") != "acme" {
		t.Fatalf("unexpected %v %v %v", out, b.lastParams, b.lastHeader)
	}
}

func TestCallGetReturnsToolError(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}, PerResourceTools: true}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"get_book_review","arguments":{"id":9}}}`, nil)
	text, isErr := toolText(t, out)
	if !isErr || !strings.Contains(text, "not_found") {
		t.Fatalf("unexpected %v", out)
	}
}

func TestUnknownMethodAndTool(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":7,"method":"resources/list"}`, nil)
	if out["error"].(map[string]any)["code"] != -32601.0 {
		t.Fatalf("unexpected %v", out)
	}
	_, out = call(t, s, `{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"nope"}}`, nil)
	if out["error"].(map[string]any)["code"] != -32602.0 {
		t.Fatalf("unexpected %v", out)
	}
	_, out = call(t, s, `not json`, nil)
	if out["error"].(map[string]any)["code"] != -32700.0 {
		t.Fatalf("unexpected %v", out)
	}
}

func TestGetIsNotAllowed(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}}
	rec := httptest.NewRecorder()
	s.ServeHTTP(rec, httptest.NewRequest("GET", "/mcp", nil))
	if rec.Code != http.StatusMethodNotAllowed {
		t.Fatalf("unexpected %d", rec.Code)
	}
}

func TestStdio(t *testing.T) {
	s := &Server{Backend: &fakeBackend{}}
	in := strings.NewReader(`{"jsonrpc":"2.0","id":1,"method":"ping"}` + "\n" + `{"jsonrpc":"2.0","method":"notifications/initialized"}` + "\n" + `{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"framework_guide"}}` + "\n")
	var out bytes.Buffer
	if err := s.ServeStdio(context.Background(), in, &out); err != nil {
		t.Fatal(err)
	}
	lines := strings.Split(strings.TrimSpace(out.String()), "\n")
	if len(lines) != 2 || !strings.Contains(lines[1], "THE GUIDE") {
		t.Fatalf("unexpected %q", out.String())
	}
}

func TestWriteTools(t *testing.T) {
	b := &fakeBackend{}
	s := &Server{Backend: b, PerResourceTools: true}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":2,"method":"tools/list"}`, nil)
	for _, tl := range out["result"].(map[string]any)["tools"].([]any) {
		m := tl.(map[string]any)
		if m["name"] == "create_book_review" {
			schema := m["inputSchema"].(map[string]any)
			if schema["properties"].(map[string]any)["book"] != nil || schema["required"].([]any)[0] != "stars" {
				t.Fatalf("unexpected create schema %v", schema)
			}
		}
	}
	_, out = call(t, s, `{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"create_book_review","arguments":{"stars":5}}}`, nil)
	if _, isErr := toolText(t, out); isErr || b.lastBody["stars"] != 5.0 {
		t.Fatalf("create: %v %v", out, b.lastBody)
	}
	_, out = call(t, s, `{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"update_book_review","arguments":{"id":3,"body":"x"}}}`, nil)
	if _, isErr := toolText(t, out); isErr || b.lastOp != "update" || b.lastID != "3" || b.lastMethod != "PATCH" || b.lastBody["body"] != "x" || b.lastBody["id"] != nil {
		t.Fatalf("update: %v %+v", out, b)
	}
	_, out = call(t, s, `{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"delete_book_review","arguments":{"id":3}}}`, nil)
	if text, isErr := toolText(t, out); isErr || b.lastOp != "delete" || !strings.Contains(text, "deleted") {
		t.Fatalf("delete: %v", out)
	}
}

func TestOperationTool(t *testing.T) {
	b := &fakeBackend{}
	s := &Server{Backend: b, PerResourceTools: true}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"get_business_export","arguments":{"author":"X"}}}`, nil)
	if text, isErr := toolText(t, out); isErr || text != "author,books\nX,1\n" || b.lastParams.Get("author") != "X" {
		t.Fatalf("unexpected %v", out)
	}
}

type shopBackend struct {
	fakeBackend
	defs  []*resource.Definition
	opID  string
	opReq script.Request
}

func (b *shopBackend) Definitions() []*resource.Definition { return b.defs }
func (b *shopBackend) Operation(_ context.Context, _ *resource.Definition, _ int, req script.Request, id string, _ map[string]any) (string, error) {
	b.opID, b.opReq = id, req
	return "{}", nil
}

func newShopBackend(t *testing.T) *shopBackend {
	t.Helper()
	dir := t.TempDir()
	for n, c := range testdb.ShopFiles {
		if err := os.WriteFile(filepath.Join(dir, n), []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	set, errs, _ := resource.Load(dir, script.NewEngine(time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	return &shopBackend{defs: set.Defs}
}

func TestToolsDocumentParametersAndItemOperations(t *testing.T) {
	b := newShopBackend(t)
	s := &Server{Backend: b, PerResourceTools: true}
	byName := map[string]tool{}
	for _, tl := range s.tools() {
		byName[tl.Name] = tl
	}
	list, ok := byName["list_book"]
	if !ok {
		t.Fatalf("no list_book in %v", byName)
	}
	props := list.InputSchema["properties"].(map[string]any)
	for _, name := range []string{"page", "title", "author", "priceCents[gte]", "publishedAt[before]", "inStock", "order[title]", "itemsPerPage"} {
		if _, ok := props[name]; !ok {
			t.Errorf("list_book misses %s: %v", name, props)
		}
	}
	item, ok := byName["get_authors_item"]
	if !ok || !strings.Contains(strings.Join(item.InputSchema["required"].([]string), ","), "id") {
		t.Fatalf("item operation tool: %+v", item)
	}
	if _, ok := byName["get_authors"]; !ok {
		t.Fatal("collection operation tool missing")
	}
	_, out := call(t, s, `{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"get_authors_item","arguments":{"id":2}}}`, nil)
	if out["error"] != nil || b.opID != "2" || b.opReq.Path != "/authors/2" {
		t.Fatalf("item call: %v id %q path %q", out, b.opID, b.opReq.Path)
	}
	_, _ = call(t, s, `{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_authors","arguments":{"itemsPerPage":2}}}`, nil)
	if b.opReq.Query.Get("itemsPerPage") != "2" || b.opReq.RawQuery != "itemsPerPage=2" {
		t.Fatalf("collection call: %+v", b.opReq)
	}
}

func TestMultiMethodOperationGetsOneToolPerMethod(t *testing.T) {
	dir := t.TempDir()
	files := map[string]string{
		"Search.json":     `{"name":"Search","operations":[{"method":["GET","POST"],"path":"/searches","query":"search.query.ts"}],"parameters":{"q":{"in":"query","type":"string"}},"properties":{"title":{"type":"string"}}}`,
		"search.query.ts": "export default (ctx) => ctx.sql`SELECT 'x' AS title`;",
	}
	for n, c := range files {
		if err := os.WriteFile(filepath.Join(dir, n), []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	set, errs, _ := resource.Load(dir, script.NewEngine(time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	b := &shopBackend{defs: set.Defs}
	s := &Server{Backend: b, PerResourceTools: true}
	byName := map[string]tool{}
	for _, tl := range s.tools() {
		byName[tl.Name] = tl
	}
	for _, name := range []string{"get_searches", "post_searches"} {
		tl, ok := byName[name]
		if !ok {
			t.Fatalf("no %s in %v", name, byName)
		}
		if _, ok := tl.InputSchema["properties"].(map[string]any)["q"]; !ok {
			t.Errorf("%s takes the parameters of the read flow: %v", name, tl.InputSchema)
		}
	}
	_, _ = call(t, s, `{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"post_searches","arguments":{"q":"dune"}}}`, nil)
	if b.opReq.Method != "POST" || b.opReq.Query.Get("q") != "dune" {
		t.Fatalf("arguments of a read-flow POST go to the query string: %+v", b.opReq)
	}
}
