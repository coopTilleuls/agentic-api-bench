package httpapi

import (
	"strings"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

func TestBrokenDefinitionsAnswerTheirErrors(t *testing.T) {
	set := &resource.Set{Broken: []resource.Broken{
		{File: "Book.json", Name: "Book", Paths: []string{"/books"}, Errors: apierr.List{
			{Code: apierr.MissingIdentifier, Resource: "Book", Pointer: "/properties", Message: "no identifier", Hint: "add one"},
			{Code: apierr.UnknownColumn, Resource: "Book", Pointer: "/properties/x", Message: "no x", Hint: "alias"},
		}},
		{File: "Oops.json", Name: "Oops.json", Errors: apierr.List{{Code: apierr.InvalidJSON, Resource: "Oops.json", Message: "bad json"}}},
	}}
	s := &Server{Set: set, Runtime: &resource.Runtime{}}
	for _, target := range []string{"/books", "/books/1", "/books.csv"} {
		rec, body := do(t, s, "GET", target)
		if rec.Code != 500 || body["code"] != "missing_identifier" || body["pointer"] != "/properties" || len(body["errors"].([]any)) != 2 || !strings.Contains(body["detail"].(string), "Book.json") {
			t.Fatalf("%s: %d %s", target, rec.Code, rec.Body)
		}
	}
	rec, body := do(t, s, "GET", "/nothing")
	if rec.Code != 404 || !strings.Contains(body["hint"].(string), "Oops.json (invalid_json)") {
		t.Fatalf("404 must point at broken files: %d %s", rec.Code, rec.Body)
	}
}
