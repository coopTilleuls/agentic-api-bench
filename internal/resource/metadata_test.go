package resource

import (
	"reflect"
	"strings"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

const shopAuthor = `{
  "name": "Author", "path": "/authors", "table": "author", "query": "author.query.ts", "readOnly": true,
  "relations": {"books": {"resource": "Book", "foreignKey": "author_id", "many": true}},
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "name": {"type": "string"},
    "publishedBooks": {"type": "integer", "computed": true},
    "books": {"type": "array", "items": {"type": "object", "properties": {"id": {"type": "integer"}, "title": {"type": "string"}}}}
  }
}`

const shopBook = `{
  "name": "Book", "path": "/books", "table": "book", "query": "book.query.ts", "itemsPerPage": 10,
  "order": {"publishedAt": "desc", "title": "asc"},
  "relations": {"author": {"resource": "Author", "column": "author_id"}},
  "envelope": {"items": "items", "total": "total"},
  "errors": {"error": "message"},
  "unknownInput": "ignore",
  "parameters": {
    "title": {"in": "query", "filter": "ipartial"},
    "author": {"in": "query", "filter": "exact", "property": "author.name"},
    "priceCents": {"in": "query", "filter": "range"},
    "publishedAt": {"in": "query", "filter": "date"},
    "inStock": {"in": "query", "type": "boolean", "handler": "in_stock.handler.ts"},
    "order": {"in": "query", "filter": "order", "properties": ["priceCents", "title", "publishedAt", "author.name"]},
    "itemsPerPage": {"in": "query", "type": "integer", "min": 1, "max": 50, "pagination": "itemsPerPage"},
    "X-Tenant": {"in": "header", "required": true, "status": 401}
  },
  "input": {
    "title": {"type": "string", "minLength": 1, "nullable": false},
    "priceCents": {"type": "integer", "min": 0}
  },
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "title": {"type": "string"},
    "priceCents": {"type": "integer", "column": "price_cents"},
    "publishedAt": {"type": "date", "column": "published_at"},
    "author": {"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}}}
  }
}`

func shopFiles() map[string]string {
	return map[string]string{
		"Author.json":         shopAuthor,
		"author.query.ts":     "export default (ctx) => ctx.sql`SELECT 1`;",
		"Book.json":           shopBook,
		"book.query.ts":       "export default (ctx) => ctx.sql`SELECT 1`;",
		"in_stock.handler.ts": "export default (value, ctx) => value ? ctx.sql`stock > 0` : ctx.sql`stock = 0`;",
	}
}

func loadShop(t *testing.T, override map[string]string) (*Set, apierr.List) {
	t.Helper()
	files := shopFiles()
	for k, v := range override {
		files[k] = v
	}
	set, errs, _ := load(t, files)
	return set, errs
}

func TestLoadV2Metadata(t *testing.T) {
	set, errs := loadShop(t, nil)
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	b := set.ByName("Book")
	if b.Table != "book" || b.QueryMod == nil {
		t.Fatalf("table and query must coexist: %+v", b)
	}
	if !reflect.DeepEqual(b.Order, []sqlf.Order{{Column: "publishedAt", Desc: true}, {Column: "title"}}) {
		t.Fatalf("order %+v", b.Order)
	}
	var names []string
	for _, p := range b.Parameters {
		names = append(names, p.Name)
	}
	if strings.Join(names, ",") != "title,author,priceCents,publishedAt,inStock,order,itemsPerPage,X-Tenant" {
		t.Fatalf("parameters not in declaration order: %v", names)
	}
	if p := b.Parameter("inStock"); p.HandlerMod == nil || p.Type != "boolean" {
		t.Fatalf("handler not loaded: %+v", p)
	}
	if p := b.Parameter("priceCents"); p.Type != "integer" {
		t.Fatalf("range type must come from the property: %+v", p)
	}
	if p := b.Parameter("author"); p.Type != "string" || p.Column() != "author_name" {
		t.Fatalf("relation path: %+v column %q", p, p.Column())
	}
	if p := b.Parameter("X-Tenant"); p.Status != 401 || !p.Required || p.Type != "string" {
		t.Fatalf("header: %+v", p)
	}
	if b.Envelope["items"] != "items" || b.Envelope["total"] != "total" || b.Errors["error"] != "message" || !b.IgnoreUnknownInput {
		t.Fatalf("output config: %+v %+v", b.Envelope, b.Errors)
	}
	pc, _ := b.Property("priceCents")
	if pc.ColumnName() != "price_cents" {
		t.Fatalf("column %q", pc.ColumnName())
	}
	au, _ := b.Property("author")
	if len(au.Properties) != 2 || au.Properties[0].Name != "id" || au.Properties[1].Name != "name" {
		t.Fatalf("nested properties not ordered: %+v", au.Properties)
	}
	in, _ := b.InputProp("title")
	if in.MinLength == nil || *in.MinLength != 1 || in.Nullable == nil || *in.Nullable {
		t.Fatalf("input constraints: %+v", in)
	}
	a := set.ByName("Author")
	books, _ := a.Property("books")
	if books.Items == nil || books.Items.Type != "object" || len(books.Items.Properties) != 2 {
		t.Fatalf("array items: %+v", books)
	}
	if r := a.Relations["books"]; r.Resource != "Book" || !r.Many || r.ForeignKey != "author_id" {
		t.Fatalf("relation: %+v", r)
	}
}

func TestParameterErrors(t *testing.T) {
	cases := []struct {
		name, param, code, pointer, hint string
	}{
		{"unknown filter", `{"in":"query","filter":"fuzzy"}`, "invalid_value", "/parameters/p/filter", "ipartial"},
		{"missing in", `{"filter":"exact","property":"title"}`, "missing_field", "/parameters/p/in", ""},
		{"unknown property", `{"in":"query","filter":"exact","property":"titel"}`, "invalid_value", "/parameters/p/property", `"title"`},
		{"unknown relation property", `{"in":"query","filter":"exact","property":"author.nam"}`, "invalid_value", "/parameters/p/property", "id, name"},
		{"range on string", `{"in":"query","filter":"range","property":"title"}`, "invalid_value", "/parameters/p/filter", "integer"},
		{"date on integer", `{"in":"query","filter":"date","property":"priceCents"}`, "invalid_value", "/parameters/p/filter", "date"},
		{"order without properties", `{"in":"query","filter":"order"}`, "missing_field", "/parameters/p/properties", ""},
		{"order unknown property", `{"in":"query","filter":"order","properties":["price"]}`, "invalid_value", "/parameters/p/properties/0", "priceCents"},
		{"pagination type", `{"in":"query","type":"string","pagination":"itemsPerPage"}`, "invalid_value", "/parameters/p/type", "integer"},
		{"handler not found", `{"in":"query","handler":"nope.handler.ts"}`, "query_file_not_found", "/parameters/p/handler", ""},
		{"filter on header", `{"in":"header","filter":"exact","property":"title"}`, "invalid_value", "/parameters/p/filter", "query"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			def := strings.Replace(shopBook, `"X-Tenant": {"in": "header", "required": true, "status": 401}`, `"X-Tenant": {"in": "header", "required": true, "status": 401}, "p": `+c.param, 1)
			_, errs := loadShop(t, map[string]string{"Book.json": def})
			e := findCode(errs, apierr.Code(c.code))
			if e == nil || e.Pointer != c.pointer || !strings.Contains(e.Hint+e.Message, c.hint) {
				t.Fatalf("want %s at %s mentioning %q, got %s", c.code, c.pointer, c.hint, errs.Error())
			}
		})
	}
}

func TestRelationErrors(t *testing.T) {
	cases := []struct {
		name, relations, pointer, hint string
	}{
		{"unknown resource", `{"author": {"resource": "Writer", "column": "author_id"}}`, "/relations/author/resource", "Author"},
		{"to-one without column", `{"author": {"resource": "Author"}}`, "/relations/author", "column"},
		{"to-many without foreign key", `{"author": {"resource": "Author", "many": true}}`, "/relations/author", "foreignKey"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			def := strings.Replace(shopBook, `{"author": {"resource": "Author", "column": "author_id"}}`, c.relations, 1)
			_, errs := loadShop(t, map[string]string{"Book.json": def})
			if len(errs) == 0 {
				t.Fatal("expected an error")
			}
			e := errs[0]
			if e.Resource != "Book" || e.Pointer != c.pointer || !strings.Contains(e.Hint+e.Message, c.hint) {
				t.Fatalf("got %s", errs.Error())
			}
		})
	}
}

func TestRelatedResourceNeedsTable(t *testing.T) {
	author := strings.Replace(shopAuthor, `"table": "author", `, "", 1)
	_, errs := loadShop(t, map[string]string{"Author.json": author})
	e := findCode(errs, apierr.InvalidValue)
	if e == nil || e.Resource != "Book" || e.Pointer != "/relations/author/resource" || !strings.Contains(e.Hint, "\"table\"") {
		t.Fatalf("got %s", errs.Error())
	}
}

func TestDefaultOrderMustNameProperties(t *testing.T) {
	_, errs := loadShop(t, map[string]string{"Book.json": strings.Replace(shopBook, `"publishedAt": "desc"`, `"published": "desc"`, 1)})
	e := findCode(errs, apierr.InvalidValue)
	if e == nil || e.Pointer != "/order/published" {
		t.Fatalf("got %s", errs.Error())
	}
	_, errs = loadShop(t, map[string]string{"Book.json": strings.Replace(shopBook, `"publishedAt": "desc"`, `"publishedAt": "down"`, 1)})
	if e := findCode(errs, apierr.InvalidValue); e == nil || e.Pointer != "/order/publishedAt" {
		t.Fatalf("got %s", errs.Error())
	}
}

func TestOperationOutputAndItemPath(t *testing.T) {
	ops := `"operations": [
    {"method": "GET", "path": "/authors", "output": ["id", "name", "publishedBooks"]},
    {"method": "GET", "path": "/authors/{id}", "output": ["id", "name", "books"]}
  ],`
	set, errs := loadShop(t, map[string]string{"Author.json": strings.Replace(shopAuthor, `"readOnly": true,`, ops, 1)})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	a := set.ByName("Author")
	if !a.Ops[1].IsItem() || a.Ops[0].IsItem() {
		t.Fatalf("item operation detection: %+v", a.Ops)
	}
	od := a.ForOperation(a.Ops[1])
	var names []string
	for _, p := range od.Output() {
		names = append(names, p.Name)
	}
	if strings.Join(names, ",") != "id,name,books" || len(od.Properties) != 4 {
		t.Fatalf("output %v, properties %d", names, len(od.Properties))
	}

	bad := strings.Replace(ops, `"books"]}`, `"bookz"]}`, 1)
	_, errs = loadShop(t, map[string]string{"Author.json": strings.Replace(shopAuthor, `"readOnly": true,`, bad, 1)})
	if e := findCode(errs, apierr.InvalidValue); e == nil || e.Pointer != "/operations/1/output/2" || !strings.Contains(e.Hint, "books") {
		t.Fatalf("got %s", errs.Error())
	}
	bad = strings.Replace(ops, `{id}`, `{slug}`, 1)
	_, errs = loadShop(t, map[string]string{"Author.json": strings.Replace(shopAuthor, `"readOnly": true,`, bad, 1)})
	if e := findCode(errs, apierr.InvalidValue); e == nil || e.Pointer != "/operations/1/path" || !strings.Contains(e.Hint, "{id}") {
		t.Fatalf("got %s", errs.Error())
	}
}

func TestNestedPropertySchema(t *testing.T) {
	_, errs := loadShop(t, map[string]string{"Book.json": strings.Replace(shopBook, `"type": "object", "properties"`, `"type": "object", "propertiez"`, 1)})
	if e := findCode(errs, apierr.UnknownField); e == nil || e.Pointer != "/properties/author/propertiez" {
		t.Fatalf("got %s", errs.Error())
	}
	_, errs = loadShop(t, map[string]string{"Book.json": strings.Replace(shopBook, `"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}}`, `"type": "object"`, 1)})
	if e := findCode(errs, apierr.MissingField); e == nil || e.Pointer != "/properties/author/properties" {
		t.Fatalf("got %s", errs.Error())
	}
	author := strings.Replace(shopAuthor, `"type": "array", "items"`, `"type": "array", "itemz"`, 1)
	_, errs = loadShop(t, map[string]string{"Author.json": author})
	if e := findCode(errs, apierr.MissingField); e == nil || e.Pointer != "/properties/books/items" {
		t.Fatalf("got %s", errs.Error())
	}
}
