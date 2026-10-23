package resource

import (
	"context"
	"encoding/json"
	"net/http"
	"reflect"
	"strings"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/testdb"
)

const notificationDef = `{
  "name": "Notification", "path": "/notifications", "table": "notification",
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "Request-Id": {"type": "string"},
    "order-data": {"type": "object", "properties": {
      "custom-fields": {"type": "object", "properties": {"request_id": {"type": "string"}}}
    }}
  },
  "input": {
    "Request-Id": {"type": "string"},
    "order-data": {"type": "object", "required": true, "properties": {
      "custom-fields": {"type": "object", "properties": {"request_id": {"type": "string", "required": true}}}
    }}
  }
}`

func notificationDefinition(t *testing.T) *Definition {
	t.Helper()
	set, errs, _ := load(t, map[string]string{"Notification.json": notificationDef})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	return set.ByName("Notification")
}

func TestFreeFormNamesLoadAndValidateNestedInput(t *testing.T) {
	d := notificationDefinition(t)
	body := map[string]any{"Request-Id": "<a@b>", "order-data": map[string]any{"custom-fields": map[string]any{"request_id": "x"}}}
	in, errs := ValidateInput(d, ModeCreate, body, nil)
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	if !reflect.DeepEqual(in, body) {
		t.Fatalf("got %#v", in)
	}
	_, errs = ValidateInput(d, ModeCreate, map[string]any{"order-data": map[string]any{"custom-fields": map[string]any{}}}, nil)
	if got := pointers(errs); !reflect.DeepEqual(got, []string{"required_property /order-data/custom-fields/request_id"}) {
		t.Fatalf("got %v", got)
	}
}

func TestFreeFormNamesDefaultColumn(t *testing.T) {
	for name, want := range map[string]string{
		"title":      "title",
		"priceCents": "priceCents",
		"order-data": "order_data",
		"Request-Id": "Request_Id",
		"a--b  c":    "a_b_c",
		"user-vars!": "user_vars_",
		"1st-place":  "_1st_place",
		"café-au":    "caf_au",
	} {
		if got := (Property{Name: name}).ColumnName(); got != want {
			t.Errorf("%q: got %q want %q", name, got, want)
		}
	}
	if got := (Property{Name: "order-data", Column: "payload"}).ColumnName(); got != "payload" {
		t.Errorf("explicit column: %q", got)
	}
}

func TestFreeFormNamesRejectReservedCharacters(t *testing.T) {
	for _, name := range []string{"a.b", "a[0]", "a]", "a b", "a\tb", "a\nb", "a b", ""} {
		def := `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"integer","identifier":true},` + jsonString(name) + `:{"type":"string"}}}`
		_, errs, _ := load(t, map[string]string{"A.json": def})
		if len(errs) == 0 || errs[0].Code != apierr.InvalidValue || !strings.Contains(errs[0].Hint, "`.`") {
			t.Errorf("%q: %v", name, errs)
		}
		def = `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"integer","identifier":true},"ok":{"type":"object","properties":{` + jsonString(name) + `:{"type":"string"}}}}}`
		if _, errs, _ = load(t, map[string]string{"A.json": def}); len(errs) == 0 {
			t.Errorf("nested %q accepted", name)
		}
		def = `{"name":"A","path":"/a","table":"a","input":{` + jsonString(name) + `:{"type":"string"}},"properties":{"id":{"type":"integer","identifier":true}}}`
		if _, errs, _ = load(t, map[string]string{"A.json": def}); len(errs) == 0 {
			t.Errorf("input %q accepted", name)
		}
	}
}

func jsonString(s string) string {
	b := new(strings.Builder)
	b.WriteByte('"')
	for _, r := range s {
		switch {
		case r == '"' || r == '\\':
			b.WriteByte('\\')
			b.WriteRune(r)
		case r < 0x20:
			b.WriteString(`\u00`)
			b.WriteString(string("0123456789abcdef"[r>>4]))
			b.WriteString(string("0123456789abcdef"[r&15]))
		default:
			b.WriteRune(r)
		}
	}
	b.WriteByte('"')
	return b.String()
}

func TestFreeFormNamesColumnCollisionIsALoadError(t *testing.T) {
	def := `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"integer","identifier":true},"order-data":{"type":"string"},"order_data":{"type":"string"}}}`
	_, errs, _ := load(t, map[string]string{"A.json": def})
	e := expectOnly(t, errs, apierr.DuplicateColumn)
	if !strings.Contains(e.Message, "order-data") || !strings.Contains(e.Message, "order_data") || !strings.Contains(e.Hint, `"column"`) {
		t.Fatalf("got %+v", e)
	}
	fixed := strings.Replace(def, `"order-data":{"type":"string"}`, `"order-data":{"type":"string","column":"payload"}`, 1)
	if _, errs, _ = load(t, map[string]string{"A.json": fixed}); len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	input := `{"name":"A","path":"/a","table":"a","input":{"order-data":{"type":"string"},"order_data":{"type":"string"}},"properties":{"id":{"type":"integer","identifier":true}}}`
	_, errs, _ = load(t, map[string]string{"A.json": input})
	expectOnly(t, errs, apierr.DuplicateColumn)
}

func TestFreeFormNamesPropertyReference(t *testing.T) {
	d := notificationDefinition(t)
	for name, want := range map[string]string{
		"Request-Id":               `r."Request-Id"`,
		"order-data":               `r."order-data"`,
		"order-data.custom-fields": `r."order-data_custom-fields"`,
	} {
		f, err := d.propertyColumn(name)
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		if got, _ := f.Render(0); got != want {
			t.Errorf("%s: got %s want %s", name, got, want)
		}
	}
}

func TestFreeFormNamesHelperAliasesAreQuoted(t *testing.T) {
	d := notificationDefinition(t)
	rs := resources{&Set{Defs: []*Definition{d}}}
	h, err := rs.Resource("Notification")
	if err != nil {
		t.Fatal(err)
	}
	f, err := h.Columns([]string{"Request-Id"})
	if err != nil {
		t.Fatal(err)
	}
	if got, _ := f.Render(0); got != `"notification"."Request_Id" AS "Request-Id"` {
		t.Fatalf("got %s", got)
	}
	if _, err := h.Column("not-a-column"); err == nil {
		t.Fatal("an unknown free-form name must not become a column")
	}
}

const notificationTable = `CREATE TABLE notification (id serial PRIMARY KEY, "Request_Id" text, order_data jsonb);`

func TestFreeFormNamesRoundTripThroughGeneratedSQL(t *testing.T) {
	pool := testdb.Pool(t, notificationTable)
	set, errs, _ := load(t, map[string]string{"Notification.json": notificationDef})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	rt := &Runtime{Pool: pool, Set: set}
	if errs := Check(context.Background(), rt, set); len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	d := set.ByName("Notification")
	req := script.Request{Method: "POST", Path: "/notifications", Header: http.Header{}}
	body := map[string]any{"Request-Id": "<a@b>", "order-data": map[string]any{"custom-fields": map[string]any{"request_id": "x"}}}
	in, errs := ValidateInput(d, ModeCreate, body, nil)
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	id, err := rt.Write(context.Background(), d, "create", req, nil, in, nil)
	if err != nil {
		t.Fatal(err)
	}
	item, ok, err := rt.Get(context.Background(), d, req, id, "read", nil)
	if err != nil || !ok {
		t.Fatalf("%v %v", ok, err)
	}
	nested, err := json.Marshal(item["order-data"])
	if err != nil || item["Request-Id"] != "<a@b>" || string(nested) != `{"custom-fields":{"request_id":"x"}}` {
		t.Fatalf("got %#v (%s)", item, nested)
	}
}

func TestSchemaPropertyNamePatternMatchesCode(t *testing.T) {
	var patterns []string
	for _, node := range []map[string]any{
		schemaDoc["properties"].(map[string]any)["properties"].(map[string]any),
		schemaDoc["properties"].(map[string]any)["input"].(map[string]any),
	} {
		patterns = append(patterns, node["propertyNames"].(map[string]any)["pattern"].(string))
	}
	for _, p := range patterns {
		if p != propertyNamePattern {
			t.Errorf("schema %q, code %q", p, propertyNamePattern)
		}
	}
}
