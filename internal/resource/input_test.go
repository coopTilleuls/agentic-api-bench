package resource

import (
	"reflect"
	"strings"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
)

const purchaseDef = `{
  "name": "Purchase", "path": "/purchases", "query": "purchase.query.ts", "command": "purchase.command.ts",
  "input": {
    "lines": {"type": "array", "required": true, "minItems": 1, "items": {"type": "object", "properties": {
      "bookId": {"type": "integer", "required": true},
      "quantity": {"type": "integer", "required": true, "min": 1}
    }}},
    "note": {"type": "string", "maxLength": 5},
    "day": {"type": "date"},
    "kind": {"type": "string", "enum": ["gift", "self"]}
  },
  "properties": {"id": {"type": "integer", "identifier": true}}
}`

func purchaseDefinition(t *testing.T, override string) *Definition {
	t.Helper()
	def := purchaseDef
	if override != "" {
		def = override
	}
	set, errs, _ := load(t, map[string]string{
		"Purchase.json":       def,
		"purchase.query.ts":   "export default (ctx) => ctx.sql`SELECT 1 AS id`;",
		"purchase.command.ts": "export function create(input, ctx) { return 1; }",
	})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	return set.ByName("Purchase")
}

func pointers(errs apierr.List) []string {
	var out []string
	for _, e := range errs {
		out = append(out, string(e.Code)+" "+e.Pointer)
	}
	return out
}

func TestNestedInputIsTyped(t *testing.T) {
	d := purchaseDefinition(t, "")
	in, errs := ValidateInput(d, ModeCreate, map[string]any{"lines": []any{map[string]any{"bookId": 1.0, "quantity": 2.0}}, "day": "2026-01-31"}, nil)
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	want := map[string]any{"lines": []any{map[string]any{"bookId": int64(1), "quantity": int64(2)}}, "day": "2026-01-31"}
	if !reflect.DeepEqual(in, want) {
		t.Fatalf("got %#v", in)
	}
}

func TestNestedInputErrorsHavePointers(t *testing.T) {
	d := purchaseDefinition(t, "")
	body := map[string]any{"lines": []any{
		map[string]any{"bookId": 1.0, "quantity": 0.0},
		map[string]any{"bookId": "2", "quantity": 1.5},
		map[string]any{"quantity": 1.0, "extra": true},
		"nope",
	}, "note": "too long", "day": "2026-02-30", "kind": "other"}
	_, errs := ValidateInput(d, ModeCreate, body, nil)
	got := strings.Join(pointers(errs), "\n")
	want := strings.Join([]string{
		"invalid_property_value /day",
		"invalid_property_value /kind",
		"invalid_property_value /lines/0/quantity",
		"invalid_property_value /lines/1/bookId",
		"invalid_property_value /lines/1/quantity",
		"required_property /lines/2/bookId",
		"unknown_property /lines/2/extra",
		"invalid_property_value /lines/3",
		"invalid_property_value /note",
	}, "\n")
	if got != want {
		t.Fatalf("got\n%s\nwant\n%s", got, want)
	}
	for _, e := range errs {
		if e.Status() != 422 {
			t.Fatalf("%s should be 422", e.Code)
		}
	}
	if e := errs[2]; !strings.Contains(e.Message, "at least 1") {
		t.Fatalf("min message: %+v", e)
	}
}

func TestEmptyArrayAndMissingRequired(t *testing.T) {
	d := purchaseDefinition(t, "")
	_, errs := ValidateInput(d, ModeCreate, map[string]any{"lines": []any{}}, nil)
	if got := pointers(errs); !reflect.DeepEqual(got, []string{"invalid_property_value /lines"}) || !strings.Contains(errs[0].Message, "at least 1") {
		t.Fatalf("got %v %s", got, errs.Error())
	}
	_, errs = ValidateInput(d, ModeCreate, map[string]any{}, nil)
	if got := pointers(errs); !reflect.DeepEqual(got, []string{"required_property /lines"}) {
		t.Fatalf("got %v", got)
	}
	_, errs = ValidateInput(d, ModeCreate, map[string]any{"lines": "x"}, nil)
	if got := pointers(errs); !reflect.DeepEqual(got, []string{"invalid_property_value /lines"}) {
		t.Fatalf("got %v", got)
	}
}

const patchBook = `{
  "name": "Book", "path": "/books", "table": "book", "unknownInput": "ignore",
  "input": {
    "title": {"type": "string", "nullable": false, "minLength": 1},
    "priceCents": {"type": "integer", "min": 0},
    "stock": {"type": "integer", "min": 0}
  },
  "properties": {"id": {"type": "integer", "identifier": true}, "title": {"type": "string"}, "priceCents": {"type": "integer", "column": "price_cents"}}
}`

func TestPatchConstraintsAndIgnoredKeys(t *testing.T) {
	set, errs, _ := load(t, map[string]string{"Book.json": patchBook})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	d := set.ByName("Book")
	in, errs := ValidateInput(d, ModePatch, map[string]any{"title": "Dune", "isbn": "x", "author": map[string]any{}}, int64(1))
	if len(errs) != 0 || !reflect.DeepEqual(in, map[string]any{"title": "Dune"}) {
		t.Fatalf("unknown keys must be ignored: %v %s", in, errs.Error())
	}
	cases := map[string]map[string]any{
		"/title":      {"title": nil},
		"/title ":     {"title": ""},
		"/priceCents": {"priceCents": -1.0},
		"/stock":      {"stock": 2.5},
		"/stock ":     {"stock": "3"},
	}
	for ptr, body := range cases {
		_, errs := ValidateInput(d, ModePatch, body, int64(1))
		if len(errs) != 1 || errs[0].Pointer != strings.TrimSpace(ptr) || errs[0].Code != apierr.InvalidPropertyValue {
			t.Fatalf("%v: got %s", body, errs.Error())
		}
	}
	in, errs = ValidateInput(d, ModePatch, map[string]any{"priceCents": 0.0, "stock": 0.0}, int64(1))
	if len(errs) != 0 || in["priceCents"] != int64(0) {
		t.Fatalf("zero is allowed: %v %s", in, errs.Error())
	}
}

func TestInputColumnMapping(t *testing.T) {
	set, _, _ := load(t, map[string]string{"Book.json": patchBook})
	d := set.ByName("Book")
	got := map[string]string{}
	for _, p := range d.InputProps() {
		got[p.Name] = d.InputColumn(p)
	}
	if !reflect.DeepEqual(got, map[string]string{"title": "title", "priceCents": "price_cents", "stock": "stock"}) {
		t.Fatalf("got %v", got)
	}
}
