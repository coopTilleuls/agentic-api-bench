package resource

import (
	"reflect"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

func shopResources(t *testing.T) script.Resources {
	t.Helper()
	set, errs, _ := load(t, testdb.ShopFiles)
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	return (&Runtime{Set: set}).resources()
}

func render(t *testing.T, f interface{ Render(int) (string, []any) }, err error) (string, []any) {
	t.Helper()
	if err != nil {
		t.Fatal(err)
	}
	return f.Render(0)
}

func TestHelperFragments(t *testing.T) {
	rs := shopResources(t)
	book, err := rs.Resource("Book")
	if err != nil {
		t.Fatal(err)
	}
	author, _ := book.Relation("author")
	cases := []struct {
		got  func() (string, []any)
		want string
	}{
		{func() (string, []any) { return render(t, book.Table(), nil) }, `"book" AS "book"`},
		{func() (string, []any) { f, e := book.Columns(nil); return render(t, f, e) }, `"book"."id" AS "id", "book"."title" AS "title", "book"."price_cents" AS "priceCents", "book"."published_at" AS "publishedAt", "book"."stock" AS "stock"`},
		{func() (string, []any) { f, e := book.Columns([]string{"title"}); return render(t, f, e) }, `"book"."title" AS "title"`},
		{func() (string, []any) { f, e := book.Column("priceCents"); return render(t, f, e) }, `"book"."price_cents"`},
		{func() (string, []any) { f, e := book.Column("published"); return render(t, f, e) }, `"book"."published"`},
		{func() (string, []any) { f, e := book.Join("author"); return render(t, f, e) }, `"book"."author_id" = "book_author"."id"`},
		{func() (string, []any) { return render(t, author.Table(), nil) }, `"author" AS "book_author"`},
		{func() (string, []any) { f, e := author.Columns(nil); return render(t, f, e) }, `"book_author"."id" AS "author_id", "book_author"."name" AS "author_name"`},
	}
	for _, c := range cases {
		sql, args := c.got()
		if sql != c.want || len(args) != 0 {
			t.Errorf("got %q %v, want %q", sql, args, c.want)
		}
	}
	sql, args := book.Identifier("4; DROP TABLE book").Render(0)
	if sql != `"book"."id" = $1` || !reflect.DeepEqual(args, []any{"4; DROP TABLE book"}) {
		t.Fatalf("identifier must bind its value: %q %v", sql, args)
	}
	if author.Alias() != "book_author" {
		t.Fatalf("alias %q", author.Alias())
	}
}

func TestToManyHelpers(t *testing.T) {
	rs := shopResources(t)
	a, _ := rs.Resource("Author")
	books, err := a.Relation("books")
	if err != nil {
		t.Fatal(err)
	}
	f, err := a.Join("books")
	sql, _ := render(t, f, err)
	if sql != `"author_books"."author_id" = "author"."id"` {
		t.Fatalf("join %q", sql)
	}
	f, err = books.JSON(nil)
	sql, _ = render(t, f, err)
	if sql != `json_build_object('id', "author_books"."id", 'title', "author_books"."title")` {
		t.Fatalf("json %q", sql)
	}
	f, err = a.Columns(nil)
	sql, _ = render(t, f, err)
	if sql != `"author"."id" AS "id", "author"."name" AS "name"` {
		t.Fatalf("computed and array properties are not columns: %q", sql)
	}
}

func TestHelpersRefuseWhatCannotBeQuoted(t *testing.T) {
	rs := shopResources(t)
	book, _ := rs.Resource("Book")
	for _, bad := range []string{`title"; DROP TABLE book; --`, "a b", "author", ""} {
		if _, err := book.Column(bad); err == nil {
			t.Errorf("column(%q) must fail", bad)
		} else if e, _ := apierr.As(err); e.Code != apierr.InvalidHelperCall {
			t.Errorf("column(%q): %v", bad, err)
		}
	}
	if _, err := book.JSON([]string{"x'); DROP TABLE book; --"}); err == nil {
		t.Error("json() with an unquotable name must fail")
	}
	if _, err := book.Relation("writer"); err == nil {
		t.Error("unknown relation must fail")
	}
	if _, err := rs.Resource("Nope"); err == nil {
		t.Error("unknown resource must fail")
	}
}
