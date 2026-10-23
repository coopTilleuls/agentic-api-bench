package resource

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

var shop = testdb.ShopFiles

func shopSet(t *testing.T, override map[string]string) (*Set, *pgxpool.Pool, apierr.List) {
	t.Helper()
	pool := testdb.Shop(t)
	files := map[string]string{}
	for k, v := range shop {
		files[k] = v
	}
	for k, v := range override {
		if v == "" {
			delete(files, k)
		} else {
			files[k] = v
		}
	}
	set, errs, _ := load(t, files)
	if len(errs) != 0 {
		t.Fatalf("static errors: %s", errs.Error())
	}
	return set, pool, Check(context.Background(), &Runtime{Pool: pool, Set: set}, set)
}

func asJSON(t *testing.T, v any) string {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func TestShopDefinitionsValidate(t *testing.T) {
	_, _, errs := shopSet(t, nil)
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
}

func TestHelpersAndNestedMapping(t *testing.T) {
	set, pool, errs := shopSet(t, nil)
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	rt := &Runtime{Pool: pool, Set: set}
	req := script.Request{Method: "GET", Header: http.Header{}}
	book := set.ByName("Book")
	item, ok, err := rt.Get(context.Background(), book, req, int64(10), "read", nil)
	if err != nil || !ok {
		t.Fatalf("%v %v", ok, err)
	}
	if got := asJSON(t, item); got != `{"author":{"id":4,"name":null},"id":10,"priceCents":800,"publishedAt":"2020-01-01","stock":2,"title":"The Anonymous Chronicle"}` {
		t.Fatalf("got %s", got)
	}
	author := set.ByName("Author")
	od := author.ForOperation(author.Ops[1])
	item, ok, err = rt.Get(context.Background(), od, req, int64(2), "read", nil)
	if err != nil || !ok {
		t.Fatalf("%v %v", ok, err)
	}
	if got := asJSON(t, item); !strings.HasPrefix(got, `{"books":[{"id":4,"title":"Dune"},{"id":5,"title":"Dune Messiah"}`) || strings.Contains(got, "publishedBooks") {
		t.Fatalf("got %s", got)
	}
	item, _, _ = rt.Get(context.Background(), od, req, int64(5), "read", nil)
	if got := asJSON(t, item); got != `{"books":[],"id":5,"name":"Isaac Asimov"}` {
		t.Fatalf("author without books: %s", got)
	}
}

func TestLookupPhaseSeesRowsHiddenFromReads(t *testing.T) {
	set, pool, _ := shopSet(t, nil)
	rt := &Runtime{Pool: pool, Set: set}
	req := script.Request{Method: "PATCH", Header: http.Header{}}
	book := set.ByName("Book")
	if _, ok, err := rt.Get(context.Background(), book, req, int64(3), "read", nil); ok || err != nil {
		t.Fatalf("unpublished book visible on read: %v %v", ok, err)
	}
	if _, ok, err := rt.Get(context.Background(), book, req, int64(3), "lookup", nil); !ok || err != nil {
		t.Fatalf("unpublished book invisible on lookup: %v %v", ok, err)
	}
}

func TestCheckNestedColumns(t *testing.T) {
	query := strings.Replace(shop["book.query.ts"], "${author.columns()}", "${author.columns([\"id\"])}", 1)
	_, _, errs := shopSet(t, map[string]string{"book.query.ts": query})
	e := expectOnly(t, errs, apierr.UnknownColumn)
	if e.Pointer != "/properties/author/properties/name" || !strings.Contains(e.Hint, "author_name") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestCheckDateAndJSONTypes(t *testing.T) {
	_, _, errs := shopSet(t, map[string]string{"book.query.ts": strings.Replace(shop["book.query.ts"], "${book.columns()}", "${book.columns([\"id\", \"title\", \"priceCents\", \"stock\"])}, book.published_at::text AS \"publishedAt\"", 1)})
	e := expectOnly(t, errs, apierr.TypeMismatch)
	if e.Pointer != "/properties/publishedAt/type" || !strings.Contains(e.Hint, "::date") {
		t.Fatalf("unexpected %+v", e)
	}
	_, _, errs = shopSet(t, map[string]string{"author.query.ts": strings.Replace(shop["author.query.ts"], "(SELECT coalesce(json_agg(${books.json()} ORDER BY ${books.column(\"id\")}), '[]') ${published})", "1", 1)})
	e = expectOnly(t, errs, apierr.TypeMismatch)
	if e.Pointer != "/properties/books/type" || !strings.Contains(e.Hint, "json") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestHelperErrorsTeach(t *testing.T) {
	_, _, errs := shopSet(t, map[string]string{"book.query.ts": strings.Replace(shop["book.query.ts"], `book.relation("author")`, `book.relation("writer")`, 1)})
	if len(errs) != 1 || errs[0].Code != apierr.InvalidHelperCall || !strings.Contains(errs[0].Hint, "author") {
		t.Fatalf("got %s", errs.Error())
	}
}

func TestProbeRejectIsNotADefinitionError(t *testing.T) {
	_, _, errs := shopSet(t, map[string]string{"book.query.ts": "export default (ctx) => { if (!ctx.validating) throw new Error('validating must be true'); ctx.reject({message: 'need a customer', status: 401}); };"})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
}
