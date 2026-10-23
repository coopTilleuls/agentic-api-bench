package resource

import (
	"context"
	"net/http"
	"net/url"
	"reflect"
	"strings"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

func shopBookDef(t *testing.T) *Definition {
	t.Helper()
	set, errs := loadShop(t, nil)
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	return set.ByName("Book")
}

func reqWith(rawQuery string, headers map[string]string) script.Request {
	q, _ := url.ParseQuery(rawQuery)
	h := http.Header{}
	for k, v := range headers {
		h.Set(k, v)
	}
	return script.Request{Method: "GET", Path: "/books", Query: q, RawQuery: rawQuery, Header: h}
}

var tenant = map[string]string{"X-Tenant": "acme"}

func prepare(t *testing.T, d *Definition, rawQuery string, headers map[string]string) (*Exec, error) {
	t.Helper()
	rt := &Runtime{}
	return rt.Prepare(context.Background(), d, reqWith(rawQuery, headers), true)
}

func rendered(frags []sqlf.Fragment) []string {
	var out []string
	for _, f := range frags {
		sql, _ := f.Render(0)
		out = append(out, sql)
	}
	return out
}

func args(frags []sqlf.Fragment) []any {
	var out []any
	for _, f := range frags {
		_, a := f.Render(0)
		out = append(out, a...)
	}
	return out
}

func wantParamError(t *testing.T, err error, status int, code apierr.Code, mention string) {
	t.Helper()
	e, ok := apierr.As(err)
	if !ok || e.Status() != status || e.Code != code || !strings.Contains(e.Message+e.Hint, mention) {
		t.Fatalf("want %d %s mentioning %q, got %v", status, code, mention, err)
	}
}

func TestPrepareDefaults(t *testing.T) {
	ex, err := prepare(t, shopBookDef(t), "", tenant)
	if err != nil {
		t.Fatal(err)
	}
	if ex.Page != 1 || ex.ItemsPerPage != 10 || len(ex.Where) != 0 {
		t.Fatalf("unexpected %+v", ex)
	}
	want := []sqlf.Order{{Column: "publishedAt", Desc: true}, {Column: "title"}, {Column: "id"}}
	if !reflect.DeepEqual(ex.Order, want) {
		t.Fatalf("default order with id tiebreak: %+v", ex.Order)
	}
}

func TestExactAndPartialFilters(t *testing.T) {
	ex, err := prepare(t, shopBookDef(t), "title=Du%25_ne&author=Frank+Herbert&unknown=1", tenant)
	if err != nil {
		t.Fatal(err)
	}
	if got := rendered(ex.Where); !reflect.DeepEqual(got, []string{`r."title"::text ILIKE $1 ESCAPE '\'`, `r."author_name" = $1`}) {
		t.Fatalf("where %q", got)
	}
	if got := args(ex.Where); !reflect.DeepEqual(got, []any{`%Du\%\_ne%`, "Frank Herbert"}) {
		t.Fatalf("args %v", got)
	}
}

func TestExactFilterAcceptsSeveralValues(t *testing.T) {
	ex, err := prepare(t, shopBookDef(t), "author[]=a&author[]=b", tenant)
	if err != nil {
		t.Fatal(err)
	}
	if got := rendered(ex.Where); !reflect.DeepEqual(got, []string{`r."author_name" = ANY($1)`}) || !reflect.DeepEqual(args(ex.Where), []any{[]string{"a", "b"}}) {
		t.Fatalf("where %q %v", got, args(ex.Where))
	}
}

func TestRangeFilter(t *testing.T) {
	ex, err := prepare(t, shopBookDef(t), "priceCents[gte]=1000&priceCents[lte]=2000&priceCents[lt]=3000&priceCents[gt]=1&priceCents[foo]=1&priceCents=5", tenant)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{`r."priceCents" > $1`, `r."priceCents" >= $1`, `r."priceCents" < $1`, `r."priceCents" <= $1`}
	if got := rendered(ex.Where); !reflect.DeepEqual(got, want) {
		t.Fatalf("where %q", got)
	}
	if got := args(ex.Where); !reflect.DeepEqual(got, []any{int64(1), int64(1000), int64(3000), int64(2000)}) {
		t.Fatalf("args %v", got)
	}
	for _, bad := range []string{"priceCents[gte]=abc", "priceCents[lte]=1.5", "priceCents[gte]="} {
		_, err := prepare(t, shopBookDef(t), bad, tenant)
		wantParamError(t, err, 400, apierr.InvalidParameter, "priceCents[")
	}
}

func TestDateFilter(t *testing.T) {
	ex, err := prepare(t, shopBookDef(t), "publishedAt[after]=2020-01-01&publishedAt[before]=2020-12-31&publishedAt[strictly_after]=2019-01-01&publishedAt[strictly_before]=2022-01-01", tenant)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{`r."publishedAt" >= $1::date`, `r."publishedAt" <= $1::date`, `r."publishedAt" > $1::date`, `r."publishedAt" < $1::date`}
	if got := rendered(ex.Where); !reflect.DeepEqual(got, want) {
		t.Fatalf("where %q", got)
	}
	if got := args(ex.Where); !reflect.DeepEqual(got, []any{"2020-01-01", "2020-12-31", "2019-01-01", "2022-01-01"}) {
		t.Fatalf("args %v", got)
	}
	for _, bad := range []string{"publishedAt[after]=2020-13-01", "publishedAt[before]=yesterday", "publishedAt[after]=2020-02-30", "publishedAt[after]=2020-1-1"} {
		_, err := prepare(t, shopBookDef(t), bad, tenant)
		wantParamError(t, err, 400, apierr.InvalidParameter, "YYYY-MM-DD")
	}
}

func TestOrderFilterKeepsRequestOrderAndAddsIDTiebreak(t *testing.T) {
	ex, err := prepare(t, shopBookDef(t), "order[title]=desc&order[priceCents]=asc&order[author.name]=DESC", tenant)
	if err != nil {
		t.Fatal(err)
	}
	want := []sqlf.Order{{Column: "title", Desc: true}, {Column: "priceCents"}, {Column: "author_name", Desc: true}, {Column: "id"}}
	if !reflect.DeepEqual(ex.Order, want) {
		t.Fatalf("order %+v", ex.Order)
	}
	ex, _ = prepare(t, shopBookDef(t), "order[priceCents]=asc&order[title]=desc", tenant)
	if ex.Order[0].Column != "priceCents" || ex.Order[1].Column != "title" {
		t.Fatalf("request order lost: %+v", ex.Order)
	}
	_, err = prepare(t, shopBookDef(t), "order[stock]=asc", tenant)
	wantParamError(t, err, 400, apierr.InvalidParameter, "priceCents, title, publishedAt, author.name")
	_, err = prepare(t, shopBookDef(t), "order[title]=up", tenant)
	wantParamError(t, err, 400, apierr.InvalidParameter, "asc or desc")
}

func TestOrderOnIdentifierIsNotRepeated(t *testing.T) {
	d := shopBookDef(t)
	d.Parameters[5].Properties = append(d.Parameters[5].Properties, "id")
	d.Parameters[5].sortColumns["id"] = "id"
	ex, err := prepare(t, d, "order[id]=desc", tenant)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(ex.Order, []sqlf.Order{{Column: "id", Desc: true}}) {
		t.Fatalf("order %+v", ex.Order)
	}
}

func TestPagination(t *testing.T) {
	ex, err := prepare(t, shopBookDef(t), "page=3&itemsPerPage=50", tenant)
	if err != nil || ex.Page != 3 || ex.ItemsPerPage != 50 {
		t.Fatalf("got %+v %v", ex, err)
	}
	for _, bad := range []string{"itemsPerPage=0", "itemsPerPage=51", "itemsPerPage=abc", "itemsPerPage=-1", "itemsPerPage=2.5"} {
		_, err := prepare(t, shopBookDef(t), bad, tenant)
		wantParamError(t, err, 400, apierr.InvalidParameter, "itemsPerPage")
	}
	for _, bad := range []string{"page=0", "page=abc", "page=-2"} {
		_, err := prepare(t, shopBookDef(t), bad, tenant)
		wantParamError(t, err, 400, apierr.InvalidPage, "page")
	}
}

func TestRequiredHeaderWithStatusRunsFirst(t *testing.T) {
	_, err := prepare(t, shopBookDef(t), "itemsPerPage=abc", nil)
	wantParamError(t, err, 401, apierr.MissingParameter, "X-Tenant")
}

func TestBooleanParameterValues(t *testing.T) {
	for in, want := range map[string]any{"true": true, "false": false, "1": true, "0": false} {
		v, err := parseParameter(Parameter{Name: "b", Type: "boolean"}, in)
		if err != nil || v != want {
			t.Fatalf("%s: %v %v", in, v, err)
		}
	}
	if _, err := parseParameter(Parameter{Name: "b", Type: "boolean"}, "yes"); err == nil {
		t.Fatal("yes is not a boolean")
	}
}

func TestEnumAndBounds(t *testing.T) {
	if _, err := parseParameter(Parameter{Name: "s", Type: "string", Enum: []string{"a", "b"}}, "c"); err == nil || !strings.Contains(err.Error(), "a, b") {
		t.Fatalf("enum: %v", err)
	}
	lo, hi := 1.0, 2.5
	if _, err := parseParameter(Parameter{Name: "n", Type: "number", Min: &lo, Max: &hi}, "3"); err == nil {
		t.Fatal("max")
	}
	if v, err := parseParameter(Parameter{Name: "n", Type: "number", Min: &lo, Max: &hi}, "2.5"); err != nil || v != 2.5 {
		t.Fatalf("%v %v", v, err)
	}
	if _, err := parseParameter(Parameter{Name: "d", Type: "datetime"}, "2020-01-01T10:00:00Z"); err != nil {
		t.Fatal(err)
	}
}

func TestQueryParametersDoNotApplyToItems(t *testing.T) {
	rt := &Runtime{}
	ex, err := rt.Prepare(context.Background(), shopBookDef(t), reqWith("itemsPerPage=abc&title=x", tenant), false)
	if err != nil || len(ex.Where) != 0 {
		t.Fatalf("got %+v %v", ex, err)
	}
	_, err = rt.Prepare(context.Background(), shopBookDef(t), reqWith("", nil), false)
	wantParamError(t, err, 401, apierr.MissingParameter, "X-Tenant")
}

func TestV1FilterablePropertiesStillFilter(t *testing.T) {
	set, errs, _ := load(t, map[string]string{"Author.json": authorJSON})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	ex, err := (&Runtime{}).Prepare(context.Background(), set.ByName("Author"), reqWith("name=Le+Guin&page=2", nil), true)
	if err != nil {
		t.Fatal(err)
	}
	if got := rendered(ex.Where); !reflect.DeepEqual(got, []string{`r."name" = $1`}) || ex.Page != 2 || ex.Filters["name"] != "Le Guin" {
		t.Fatalf("got %q %+v", got, ex)
	}
	_, err = (&Runtime{}).Prepare(context.Background(), set.ByName("Author"), reqWith("id=1", nil), true)
	wantParamError(t, err, 400, apierr.FilterNotEnabled, "filterable")
}

func TestParameterValuesAreExposed(t *testing.T) {
	ex, err := prepare(t, shopBookDef(t), "title=dune&publishedAt[after]=2020-01-01&order[title]=asc", tenant)
	if err != nil {
		t.Fatal(err)
	}
	if ex.Values["title"] != "dune" || ex.Values["X-Tenant"] != "acme" {
		t.Fatalf("values %+v", ex.Values)
	}
	if m, ok := ex.Values["publishedAt"].(map[string]any); !ok || m["after"] != "2020-01-01" {
		t.Fatalf("values %+v", ex.Values)
	}
}

func TestHandlerReferencesOuterColumnsWithProperty(t *testing.T) {
	for file, src := range map[string]string{
		"h.handler.ts": "export default (v, ctx) => ctx.sql`${ctx.property('priceCents')} > ${v} AND ${ctx.property('author.name')} IS NOT NULL`;",
		"h.star":       "def handler(v, ctx):\n    return ctx.sql('{p} > {v} AND {a} IS NOT NULL', p = ctx.property('priceCents'), v = v, a = ctx.property('author.name'))\n",
	} {
		def := strings.Replace(shopBook, `"in_stock.handler.ts"`, `"`+file+`"`, 1)
		set, errs := loadShop(t, map[string]string{"Book.json": def, file: src})
		if len(errs) != 0 {
			t.Fatal(errs.Error())
		}
		ex, err := prepare(t, set.ByName("Book"), "inStock=1", tenant)
		if err != nil {
			t.Fatalf("%s: %v", file, err)
		}
		if got := rendered(ex.Where); !reflect.DeepEqual(got, []string{`r."priceCents" > $1 AND r."author_name" IS NOT NULL`}) {
			t.Fatalf("%s: %q", file, got)
		}
	}
	set, _ := loadShop(t, map[string]string{"in_stock.handler.ts": "export default (v, ctx) => ctx.sql`${ctx.property('stok')} > 0`;"})
	_, err := prepare(t, set.ByName("Book"), "inStock=1", tenant)
	if e, ok := apierr.As(err); !ok || e.Code != apierr.InvalidHelperCall || !strings.Contains(e.Hint, "priceCents") {
		t.Fatalf("got %v", err)
	}
}
