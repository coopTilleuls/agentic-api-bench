package sqlf

import (
	"reflect"
	"testing"
)

func TestTaggedRendersPlaceholders(t *testing.T) {
	f := Tagged([]string{"SELECT * FROM book WHERE tenant = ", " AND year > ", ""}, []any{"acme", 1990})
	sql, args := f.Render(0)
	if sql != "SELECT * FROM book WHERE tenant = $1 AND year > $2" {
		t.Fatalf("unexpected sql %q", sql)
	}
	if !reflect.DeepEqual(args, []any{"acme", 1990}) {
		t.Fatalf("unexpected args %v", args)
	}
}

func TestTaggedInlinesNestedFragments(t *testing.T) {
	inner := Tagged([]string{"AND b.year > ", ""}, []any{2000})
	f := Tagged([]string{"SELECT 1 WHERE a = ", " ", ""}, []any{"x", inner})
	sql, args := f.Render(2)
	if sql != "SELECT 1 WHERE a = $3 AND b.year > $4" {
		t.Fatalf("unexpected sql %q", sql)
	}
	if !reflect.DeepEqual(args, []any{"x", 2000}) {
		t.Fatalf("unexpected args %v", args)
	}
}

func TestTableSourceQuotesIdentifiers(t *testing.T) {
	sql, args := TableSource("public.book", []string{"id", "authorName"}).Render(0)
	if sql != `SELECT "id", "authorName" FROM "public"."book"` {
		t.Fatalf("unexpected sql %q", sql)
	}
	if len(args) != 0 {
		t.Fatalf("unexpected args %v", args)
	}
}

func TestCollectionComposesWhereOrderAndPagination(t *testing.T) {
	src := Tagged([]string{"SELECT b.id, a.name AS author FROM book b JOIN author a ON a.id = b.author_id WHERE b.tenant = ", ";  \n"}, []any{"acme"})
	q := Collection(src, Select{Where: []Fragment{Eq("author", "Le Guin")}, Order: []Order{{Column: "id"}}, Limit: 30, Offset: 60})
	want := "SELECT * FROM (\nSELECT b.id, a.name AS author FROM book b JOIN author a ON a.id = b.author_id WHERE b.tenant = $1\n) r WHERE r.\"author\" = $2 ORDER BY r.\"id\" ASC LIMIT $3 OFFSET $4"
	if q.SQL != want {
		t.Fatalf("unexpected sql\n got %q\nwant %q", q.SQL, want)
	}
	if !reflect.DeepEqual(q.Args, []any{"acme", "Le Guin", 30, 60}) {
		t.Fatalf("unexpected args %v", q.Args)
	}
}

func TestCollectionMultiKeyOrder(t *testing.T) {
	q := Collection(Raw("SELECT 1"), Select{Order: []Order{{Column: "priceCents", Desc: true}, {Column: "title"}, {Column: "id"}}, Limit: 10})
	want := "SELECT * FROM (\nSELECT 1\n) r ORDER BY r.\"priceCents\" DESC, r.\"title\" ASC, r.\"id\" ASC LIMIT $1 OFFSET $2"
	if q.SQL != want {
		t.Fatalf("unexpected sql %q", q.SQL)
	}
}

func TestCountComposesWhere(t *testing.T) {
	q := Count(Raw("SELECT id FROM book"), []Fragment{Eq("published", true), Cmp("price", ">=", int64(10))})
	want := "SELECT count(*) FROM (\nSELECT id FROM book\n) r WHERE r.\"published\" = $1 AND r.\"price\" >= $2"
	if q.SQL != want {
		t.Fatalf("unexpected sql %q", q.SQL)
	}
}

func TestWhereFragmentsAreParenthesized(t *testing.T) {
	q := Count(Raw("SELECT 1"), []Fragment{Raw("a OR b"), Raw("c")})
	if q.SQL != "SELECT count(*) FROM (\nSELECT 1\n) r WHERE (a OR b) AND (c)" {
		t.Fatalf("unexpected sql %q", q.SQL)
	}
}

func TestCountWithoutFilters(t *testing.T) {
	q := Count(Raw("SELECT id FROM book"), nil)
	if q.SQL != "SELECT count(*) FROM (\nSELECT id FROM book\n) r" {
		t.Fatalf("unexpected sql %q", q.SQL)
	}
}

func TestItemLookup(t *testing.T) {
	q := Item(Tagged([]string{"SELECT id FROM book WHERE t = ", ""}, []any{"x"}), "id", int64(4), nil)
	if q.SQL != "SELECT * FROM (\nSELECT id FROM book WHERE t = $1\n) r WHERE r.\"id\" = $2" {
		t.Fatalf("unexpected sql %q", q.SQL)
	}
	if !reflect.DeepEqual(q.Args, []any{"x", int64(4)}) {
		t.Fatalf("unexpected args %v", q.Args)
	}
}

func TestItemLookupWithExtraWhere(t *testing.T) {
	q := Item(Raw("SELECT id FROM book"), "id", int64(4), []Fragment{Raw("stock > 0")})
	if q.SQL != "SELECT * FROM (\nSELECT id FROM book\n) r WHERE r.\"id\" = $1 AND (stock > 0)" {
		t.Fatalf("unexpected sql %q", q.SQL)
	}
}

func TestProbe(t *testing.T) {
	q := Probe(Raw("SELECT 1 AS id -- trailing comment"))
	if q.SQL != "SELECT * FROM (\nSELECT 1 AS id -- trailing comment\n) r LIMIT 0" {
		t.Fatalf("unexpected sql %q", q.SQL)
	}
	if q.SQL[q.SourceOffset:q.SourceOffset+8] != "SELECT 1" {
		t.Fatalf("unexpected source offset %d", q.SourceOffset)
	}
}

func TestAllHasNoPagination(t *testing.T) {
	q := All(Raw("SELECT id FROM book"), Select{Where: []Fragment{Eq("author", "x")}, Order: []Order{{Column: "id"}}})
	if q.SQL != "SELECT * FROM (\nSELECT id FROM book\n) r WHERE r.\"author\" = $1 ORDER BY r.\"id\" ASC" {
		t.Fatalf("unexpected sql %q", q.SQL)
	}
}

func TestAllWithoutOrderOrWhereIsTheSourceItself(t *testing.T) {
	q := All(Raw("SELECT id FROM book ORDER BY id DESC -- newest;"), Select{})
	if q.SQL != "SELECT id FROM book ORDER BY id DESC -- newest" || q.SourceOffset != 0 {
		t.Fatalf("unexpected sql %q", q.SQL)
	}
}

func TestCollectionWithoutOrderOrWherePaginatesTheSourceDirectly(t *testing.T) {
	q := Collection(Tagged([]string{"SELECT id FROM book WHERE t = ", " ORDER BY id DESC -- c"}, []any{"x"}), Select{Limit: 10, Offset: 20})
	if q.SQL != "SELECT id FROM book WHERE t = $1 ORDER BY id DESC -- c\nLIMIT $2 OFFSET $3" || !reflect.DeepEqual(q.Args, []any{"x", 10, 20}) {
		t.Fatalf("unexpected sql %q %v", q.SQL, q.Args)
	}
}

func TestEscapeLike(t *testing.T) {
	for in, want := range map[string]string{"dune": "dune", "100%": `100\%`, "a_b": `a\_b`, `back\slash`: `back\\slash`, `%_\`: `\%\_\\`} {
		if got := EscapeLike(in); got != want {
			t.Errorf("EscapeLike(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestLikeBindsEscapedPattern(t *testing.T) {
	sql, args := Like("title", "50%_off", true).Render(0)
	if sql != `r."title"::text ILIKE $1 ESCAPE '\'` {
		t.Fatalf("unexpected sql %q", sql)
	}
	if !reflect.DeepEqual(args, []any{`%50\%\_off%`}) {
		t.Fatalf("unexpected args %v", args)
	}
	sql, _ = Like("title", "x", false).Render(0)
	if sql != `r."title"::text LIKE $1 ESCAPE '\'` {
		t.Fatalf("unexpected sql %q", sql)
	}
}

func TestColumnIdentifiersAreQuoted(t *testing.T) {
	sql, args := Eq(`a"; DROP TABLE x; --`, "v").Render(0)
	if sql != `r."a""; DROP TABLE x; --" = $1` || !reflect.DeepEqual(args, []any{"v"}) {
		t.Fatalf("unexpected %q %v", sql, args)
	}
}

func TestCmpCastsValue(t *testing.T) {
	sql, args := CmpCast("publishedAt", "<=", "2020-12-31", "date").Render(0)
	if sql != `r."publishedAt" <= $1::date` || !reflect.DeepEqual(args, []any{"2020-12-31"}) {
		t.Fatalf("unexpected %q %v", sql, args)
	}
}

func TestInBindsArray(t *testing.T) {
	sql, args := In("author", []string{"a", "b"}).Render(0)
	if sql != `r."author" = ANY($1)` || !reflect.DeepEqual(args, []any{[]string{"a", "b"}}) {
		t.Fatalf("unexpected %q %v", sql, args)
	}
}

func TestJoinFragments(t *testing.T) {
	sql, args := Join([]Fragment{Tagged([]string{"a = ", ""}, []any{1}), Tagged([]string{"b = ", ""}, []any{2})}, ", ").Render(0)
	if sql != "a = $1, b = $2" || !reflect.DeepEqual(args, []any{1, 2}) {
		t.Fatalf("unexpected %q %v", sql, args)
	}
}
