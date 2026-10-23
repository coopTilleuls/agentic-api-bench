package httpapi

import (
	"encoding/json"
	"net/http/httptest"
	"strings"
	"testing"
)

func send(t *testing.T, s *Server, method, target, contentType, accept, body string) (*httptest.ResponseRecorder, map[string]any) {
	t.Helper()
	req := httptest.NewRequest(method, target, strings.NewReader(body))
	if contentType != "" {
		req.Header.Set("Content-Type", contentType)
	}
	if accept != "" {
		req.Header.Set("Accept", accept)
	}
	rec := httptest.NewRecorder()
	s.ServeHTTP(rec, req)
	var out map[string]any
	_ = json.Unmarshal(rec.Body.Bytes(), &out)
	return rec, out
}

func TestCreateUpdateDeleteOnTable(t *testing.T) {
	s := newServer(t)
	rec, body := send(t, s, "POST", "/authors", "application/json", "", `{"name":"Octavia Butler"}`)
	if rec.Code != 201 || rec.Header().Get("Location") != "/authors/3" || body["@id"] != "/authors/3" || body["name"] != "Octavia Butler" {
		t.Fatalf("create: %d %s %v", rec.Code, rec.Body, rec.Header())
	}
	rec, body = send(t, s, "PATCH", "/authors/3", "application/merge-patch+json", "", `{"name":"Octavia E. Butler"}`)
	if rec.Code != 200 || body["name"] != "Octavia E. Butler" {
		t.Fatalf("patch: %d %s", rec.Code, rec.Body)
	}
	rec, _ = send(t, s, "PUT", "/authors/3", "application/ld+json", "", `{"@id":"/authors/3","name":"O. Butler"}`)
	if rec.Code != 200 {
		t.Fatalf("put: %d %s", rec.Code, rec.Body)
	}
	rec, _ = send(t, s, "DELETE", "/authors/3", "", "", "")
	if rec.Code != 204 || rec.Body.Len() != 0 {
		t.Fatalf("delete: %d %s", rec.Code, rec.Body)
	}
	rec, _ = send(t, s, "DELETE", "/authors/3", "", "", "")
	if rec.Code != 404 {
		t.Fatalf("delete missing: %d %s", rec.Code, rec.Body)
	}
}

func TestWriteErrors(t *testing.T) {
	s := newServer(t)
	cases := []struct {
		method, target, ct, body string
		status                   int
		code, pointer            string
	}{
		{"POST", "/authors", "application/json", `{"nam":"x"}`, 422, "unknown_property", "/nam"},
		{"POST", "/authors", "application/json", `{"name":1}`, 422, "invalid_property_value", "/name"},
		{"POST", "/authors", "application/json", `[{"name":"x"}]`, 400, "invalid_body", ""},
		{"POST", "/authors", "application/json", `{"name":`, 400, "invalid_body", ""},
		{"POST", "/authors", "text/plain", `name`, 415, "unsupported_media_type", ""},
		{"POST", "/authors", "application/json", `{"name":null}`, 422, "constraint_violation", "/name"},
		{"DELETE", "/books/1", "", ``, 405, "operation_not_supported", ""},
		{"POST", "/books", "application/json", `{"title":"x","author":"y","author_id":1}`, 422, "unknown_property", "/author"},
		{"POST", "/authors/1", "application/json", `{}`, 405, "method_not_allowed", ""},
		{"PATCH", "/authors/99", "application/json", `{}`, 404, "not_found", ""},
	}
	for _, c := range cases {
		rec, body := send(t, s, c.method, c.target, c.ct, "", c.body)
		if rec.Code != c.status || body["code"] != c.code || (c.pointer != "" && body["pointer"] != c.pointer) {
			t.Errorf("%s %s %s: %d %s", c.method, c.target, c.body, rec.Code, rec.Body)
		}
	}
}

func TestOutputFormats(t *testing.T) {
	s := newServer(t)
	rec, _ := send(t, s, "GET", "/authors", "", "text/csv", "")
	if rec.Code != 200 || !strings.HasPrefix(rec.Header().Get("Content-Type"), "text/csv") || rec.Body.String() != "id,name\n1,Ursula K. Le Guin\n2,Frank Herbert\n" {
		t.Fatalf("csv: %d %q", rec.Code, rec.Body)
	}
	rec, _ = send(t, s, "GET", "/books?page=2", "", "text/csv", "")
	if rec.Body.String() != "id,title,author\n4,Dune,Frank Herbert\n" {
		t.Fatalf("csv pagination: %q", rec.Body)
	}
	rec, _ = send(t, s, "GET", "/authors/1", "", "application/json", "")
	if rec.Body.String() != `{"id":1,"name":"Ursula K. Le Guin"}`+"\n" {
		t.Fatalf("json item: %q", rec.Body)
	}
	rec, _ = send(t, s, "GET", "/authors", "", "application/json", "")
	if rec.Body.String() != `[{"id":1,"name":"Ursula K. Le Guin"},{"id":2,"name":"Frank Herbert"}]`+"\n" {
		t.Fatalf("json collection: %q", rec.Body)
	}
	rec, _ = send(t, s, "GET", "/authors", "", "text/html,application/xhtml+xml,*/*;q=0.8", "")
	if !strings.HasPrefix(rec.Header().Get("Content-Type"), "application/ld+json") {
		t.Fatalf("browser accept: %v", rec.Header())
	}
	rec, body := send(t, s, "GET", "/authors", "", "application/xml", "")
	if rec.Code != 406 || body["code"] != "not_acceptable" {
		t.Fatalf("406: %d %s", rec.Code, rec.Body)
	}
}

func TestCSVIsNotAnInputFormat(t *testing.T) {
	s := newServer(t)
	rec, body := send(t, s, "POST", "/authors", "text/csv", "", "name\nA\n")
	if rec.Code != 415 || body["code"] != "unsupported_media_type" || !strings.Contains(body["hint"].(string), "output format only") {
		t.Fatalf("unexpected %d %s", rec.Code, rec.Body)
	}
}

func TestCSVExportIsUnpaginatedUnlessPageGiven(t *testing.T) {
	s := newServer(t)
	for _, target := range []string{"/books?format=csv", "/books.csv"} {
		rec, _ := send(t, s, "GET", target, "", "", "")
		if rec.Code != 200 || rec.Body.String() != "id,title,author\n1,A Wizard of Earthsea,Ursula K. Le Guin\n2,The Left Hand of Darkness,Ursula K. Le Guin\n4,Dune,Frank Herbert\n" {
			t.Fatalf("%s: %d %q", target, rec.Code, rec.Body)
		}
		if rec.Header().Get("Content-Disposition") != `attachment; filename="books.csv"` {
			t.Fatalf("%s: %v", target, rec.Header())
		}
	}
	rec, _ := send(t, s, "GET", "/books.csv?author=Frank+Herbert", "", "", "")
	if rec.Body.String() != "id,title,author\n4,Dune,Frank Herbert\n" {
		t.Fatalf("filtered export: %q", rec.Body)
	}
	rec, _ = send(t, s, "GET", "/books/4.json", "", "", "")
	if rec.Body.String() != `{"id":4,"title":"Dune","author":"Frank Herbert"}`+"\n" {
		t.Fatalf("item suffix: %q", rec.Body)
	}
	rec, body := send(t, s, "GET", "/books?format=xml", "", "", "")
	if rec.Code != 406 || body["code"] != "not_acceptable" {
		t.Fatalf("unknown format: %d %s", rec.Code, rec.Body)
	}
}

func TestWriteInvisibleThroughQuery(t *testing.T) {
	s := newServer(t)
	rec, _ := send(t, s, "POST", "/books", "application/json", "", `{"title":"Draft","author_id":1}`)
	if rec.Code != 201 || rec.Header().Get("Location") != "/books/5" || rec.Body.Len() != 0 {
		t.Fatalf("unpublished create: %d %q %v", rec.Code, rec.Body, rec.Header())
	}
	rec, body := send(t, s, "POST", "/books", "application/json", "", `{"title":"Out","author_id":1,"published":true}`)
	if rec.Code != 201 || body["title"] != "Out" {
		t.Fatalf("published create: %d %s", rec.Code, rec.Body)
	}
	rec, _ = send(t, s, "PATCH", "/books/1", "application/json", "", `{"published":false}`)
	if rec.Code != 204 || rec.Body.Len() != 0 {
		t.Fatalf("unpublishing patch: %d %q", rec.Code, rec.Body)
	}
	rec, _ = send(t, s, "PATCH", "/books/1", "application/json", "", `{"published":true}`)
	if rec.Code != 404 {
		t.Fatalf("invisible row must stay 404: %d %q", rec.Code, rec.Body)
	}
}
