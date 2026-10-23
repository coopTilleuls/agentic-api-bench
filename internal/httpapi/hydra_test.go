package httpapi

import (
	"encoding/json"
	"net/url"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

func bookDef() *resource.Definition {
	return &resource.Definition{Name: "Book", Path: "/books", ItemsPerPage: 2, Properties: []resource.Property{
		{Name: "id", Type: "integer", Identifier: true},
		{Name: "title", Type: "string"},
		{Name: "author", Type: "string", Filterable: true},
	}}
}

func mustJSON(t *testing.T, v any) string {
	t.Helper()
	b, err := Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func TestItemDocumentKeepsPropertyOrder(t *testing.T) {
	doc := itemDoc(bookDef(), resource.Item{"title": "Dune", "id": int64(4), "author": "Frank Herbert"}, true)
	want := `{"@context":"/contexts/Book","@id":"/books/4","@type":"Book","id":4,"title":"Dune","author":"Frank Herbert"}`
	if got := mustJSON(t, doc); got != want {
		t.Fatalf("got  %s\nwant %s", got, want)
	}
}

func TestCollectionDocumentWithViewAndSearch(t *testing.T) {
	items := []resource.Item{{"id": int64(3), "title": "C", "author": "X"}, {"id": int64(4), "title": "D", "author": "X"}}
	u, _ := url.Parse("/books?author=X&page=2")
	doc := collectionDoc(bookDef(), items, 5, 2, 0, u)
	want := `{"@context":"/contexts/Book","@id":"/books","@type":"Collection","totalItems":5,"member":[` +
		`{"@id":"/books/3","@type":"Book","id":3,"title":"C","author":"X"},{"@id":"/books/4","@type":"Book","id":4,"title":"D","author":"X"}],` +
		`"view":{"@id":"/books?author=X&page=2","@type":"PartialCollectionView","first":"/books?author=X&page=1","last":"/books?author=X&page=3","previous":"/books?author=X&page=1","next":"/books?author=X&page=3"},` +
		`"search":{"@type":"IriTemplate","template":"/books{?author}","variableRepresentation":"BasicRepresentation","mapping":[{"@type":"IriTemplateMapping","variable":"author","property":"author","required":false}]}}`
	if got := mustJSON(t, doc); got != want {
		t.Fatalf("got  %s\nwant %s", got, want)
	}
}

func TestCollectionWithoutPaginationOrFiltersHasNoView(t *testing.T) {
	d := bookDef()
	d.Properties[2].Filterable = false
	u, _ := url.Parse("/books")
	got := mustJSON(t, collectionDoc(d, []resource.Item{}, 1, 1, 0, u))
	want := `{"@context":"/contexts/Book","@id":"/books","@type":"Collection","totalItems":1,"member":[]}`
	if got != want {
		t.Fatalf("got %s", got)
	}
}

func TestContextDocumentSkipsIdentifier(t *testing.T) {
	got := mustJSON(t, contextDoc(bookDef(), "http://localhost:8080"))
	want := `{"@context":{"@vocab":"http://localhost:8080/docs.jsonld#","hydra":"http://www.w3.org/ns/hydra/core#","title":"Book/title","author":"Book/author"}}`
	if got != want {
		t.Fatalf("got %s", got)
	}
}

func TestProblemDocument(t *testing.T) {
	e := &apierr.Error{Code: apierr.FilterNotEnabled, Resource: "Book", Pointer: "/properties/title", Message: "m", Hint: "h"}
	got := mustJSON(t, problemDoc(e))
	want := `{"type":"/errors/400","title":"An error occurred","status":400,"detail":"m","code":"filter_not_enabled","hint":"h see: caddy resource guide parameters","resource":"Book","pointer":"/properties/title"}`
	if got != want {
		t.Fatalf("got %s", got)
	}
}

func TestShapedErrorHintEndsWithTheGuideTopic(t *testing.T) {
	e := &apierr.Error{Code: apierr.FilterNotEnabled, Pointer: "/properties/title", Message: "m", Hint: "h"}
	got := mustJSON(t, shapedError(resource.ErrorShape{"error": "message", "help": "hint"}, e, nil, true))
	want := `{"error":"m","help":"h see: caddy resource guide parameters","code":"filter_not_enabled","hint":"h see: caddy resource guide parameters","pointer":"/properties/title"}`
	if got != want {
		t.Fatalf("got %s", got)
	}
}

func TestOpenAPIDocument(t *testing.T) {
	d := bookDef()
	d.Table = "book"
	doc := openAPIDoc(&resource.Set{Defs: []*resource.Definition{d}}, Output{})
	var m map[string]any
	if err := json.Unmarshal([]byte(mustJSON(t, doc)), &m); err != nil {
		t.Fatal(err)
	}
	paths := m["paths"].(map[string]any)
	if _, ok := paths["/books"]; !ok {
		t.Fatalf("missing /books: %v", paths)
	}
	item := paths["/books/{id}"].(map[string]any)["get"].(map[string]any)
	if item["operationId"] != "getBook" {
		t.Fatalf("unexpected %v", item)
	}
	coll := paths["/books"].(map[string]any)["get"].(map[string]any)
	params := coll["parameters"].([]any)
	if len(params) != 2 || params[1].(map[string]any)["name"] != "author" {
		t.Fatalf("unexpected params %v", params)
	}
	if paths["/books"].(map[string]any)["post"] == nil || paths["/books/{id}"].(map[string]any)["delete"] == nil {
		t.Fatalf("table resources expose writes: %v", paths)
	}
}
