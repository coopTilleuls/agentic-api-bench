package resource

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
)

func writeDir(t *testing.T, files map[string]string) string {
	t.Helper()
	dir := t.TempDir()
	for name, content := range files {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return dir
}

func load(t *testing.T, files map[string]string) (*Set, apierr.List, apierr.List) {
	t.Helper()
	return Load(writeDir(t, files), script.NewEngine(time.Second))
}

func findCode(l apierr.List, c apierr.Code) *apierr.Error {
	for _, e := range l {
		if e.Code == c {
			return e
		}
	}
	return nil
}

func expectOnly(t *testing.T, errs apierr.List, c apierr.Code) *apierr.Error {
	t.Helper()
	if len(errs) != 1 || errs[0].Code != c {
		t.Fatalf("expected exactly one %s, got %s", c, errs.Error())
	}
	return errs[0]
}

const authorJSON = `{
  "name": "Author",
  "path": "/authors",
  "table": "author",
  "itemsPerPage": 10,
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "name": {"type": "string", "filterable": true}
  }
}`

func TestLoadJSONDefinition(t *testing.T) {
	set, errs, warns := load(t, map[string]string{"Author.json": authorJSON})
	if len(errs) != 0 || len(warns) != 0 {
		t.Fatalf("errs %s warns %s", errs.Error(), warns.Error())
	}
	d := set.ByName("Author")
	if d == nil || d.Path != "/authors" || d.Table != "author" || d.ItemsPerPage != 10 {
		t.Fatalf("unexpected %+v", d)
	}
	if len(d.Properties) != 2 || d.Properties[0].Name != "id" || d.Properties[1].Name != "name" || !d.Properties[1].Filterable {
		t.Fatalf("properties not loaded in declaration order: %+v", d.Properties)
	}
	if d.Identifier().Name != "id" {
		t.Fatalf("unexpected identifier %+v", d.Identifier())
	}
}

func TestLoadTSDefinitionWithQuery(t *testing.T) {
	set, errs, _ := load(t, map[string]string{
		"Book.ts": `export default defineResource({
  name: "Book", path: "/books", query: "book.query.ts",
  properties: { id: { type: "integer", identifier: true }, title: { type: "string" } },
});`,
		"book.query.ts": "export default function query(ctx: QueryContext): Sql { return ctx.sql`SELECT id, title FROM book`; }",
	})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	d := set.ByName("Book")
	if d.ItemsPerPage != DefaultItemsPerPage || d.QueryMod == nil {
		t.Fatalf("unexpected %+v", d)
	}
}

func TestDefaultItemsPerPage(t *testing.T) {
	set, _, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"string","identifier":true}}}`})
	if set.ByName("A").ItemsPerPage != 30 {
		t.Fatal("default itemsPerPage should be 30")
	}
}

func TestInvalidJSONHasLineAndColumn(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"Author.json": "{\n  \"name\": \"Author\",\n  \"path\": \"/a\"\n  \"table\": \"a\"\n}"})
	e := expectOnly(t, errs, apierr.InvalidJSON)
	if !strings.Contains(e.Message, "line 4") || e.Resource != "Author.json" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestUnknownFieldSuggestsClosestKey(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","itemPerPage":3,"properties":{"id":{"type":"integer","identifier":true}}}`})
	e := expectOnly(t, errs, apierr.UnknownField)
	if e.Pointer != "/itemPerPage" || !strings.Contains(e.Hint, "itemsPerPage") || e.Resource != "A" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestUnknownPropertyKeyListsAllowedKeys(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"integer","identifier":true,"columnn":"x"}}}`})
	e := expectOnly(t, errs, apierr.UnknownField)
	if e.Pointer != "/properties/id/columnn" || !strings.Contains(e.Hint, "filterable") || !strings.Contains(e.Hint, `Did you mean "column"`) {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestMissingRequiredField(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","table":"a","properties":{"id":{"type":"integer","identifier":true}}}`})
	e := expectOnly(t, errs, apierr.MissingField)
	if e.Pointer != "/path" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestInvalidPropertyTypeListsAllowedTypes(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"int","identifier":true}}}`})
	e := expectOnly(t, errs, apierr.InvalidValue)
	if e.Pointer != "/properties/id/type" || !strings.Contains(e.Hint, "integer") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestInvalidJSONType(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","itemsPerPage":"10","properties":{"id":{"type":"integer","identifier":true}}}`})
	e := expectOnly(t, errs, apierr.InvalidType)
	if e.Pointer != "/itemsPerPage" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestPatternViolationUsesFieldHint(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"books","table":"a","properties":{"id":{"type":"integer","identifier":true}}}`})
	e := expectOnly(t, errs, apierr.InvalidValue)
	if e.Pointer != "/path" || !strings.Contains(e.Hint, "/books") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestMissingIdentifier(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"integer"}}}`})
	if e := expectOnly(t, errs, apierr.MissingIdentifier); e.Pointer != "/properties" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestMultipleIdentifiers(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"integer","identifier":true},"code":{"type":"string","identifier":true}}}`})
	expectOnly(t, errs, apierr.MultipleIdentifiers)
}

func TestIdentifierMustBeIntegerOrString(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"boolean","identifier":true}}}`})
	if e := expectOnly(t, errs, apierr.InvalidValue); e.Pointer != "/properties/id/type" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestTableOrQueryRequired(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","properties":{"id":{"type":"integer","identifier":true}}}`})
	expectOnly(t, errs, apierr.MissingSource)
	_, errs, _ = load(t, map[string]string{
		"A.json":     `{"name":"A","path":"/a","table":"a","query":"a.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`,
		"a.query.ts": "export default (ctx) => ctx.sql`SELECT 1 AS id`;",
	})
	if len(errs) != 0 {
		t.Fatalf("table with query is the table's metadata plus a custom read: %s", errs.Error())
	}
}

func TestQueryFileNotFoundListsCandidates(t *testing.T) {
	_, errs, _ := load(t, map[string]string{
		"A.json":      `{"name":"A","path":"/a","query":"a.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`,
		"aa.query.ts": "export default (ctx) => ctx.sql`SELECT 1 AS id`;",
	})
	e := expectOnly(t, errs, apierr.QueryFileNotFound)
	if e.Pointer != "/query" || !strings.Contains(e.Hint, "aa.query.ts") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestQueryTranspileErrorIsReportedOnDefinition(t *testing.T) {
	_, errs, _ := load(t, map[string]string{
		"A.json":     `{"name":"A","path":"/a","query":"a.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`,
		"a.query.ts": "export default (ctx) => ctx.sql`SELECT 1 AS id`;;)",
	})
	e := expectOnly(t, errs, apierr.TranspileError)
	if e.Resource != "A" || e.Pointer != "/query" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestTSDefinitionExportingFunctionHintsAtQueryNaming(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"book.ts": "export default (ctx) => ctx.sql`SELECT 1`;"})
	e := expectOnly(t, errs, apierr.InvalidExport)
	if !strings.Contains(e.Hint, ".query.ts") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestDuplicateNameAndPath(t *testing.T) {
	_, errs, _ := load(t, map[string]string{
		"A.json": `{"name":"A","path":"/a","table":"a","properties":{"id":{"type":"integer","identifier":true}}}`,
		"B.json": `{"name":"A","path":"/a","table":"b","properties":{"id":{"type":"integer","identifier":true}}}`,
	})
	if findCode(errs, apierr.DuplicateName) == nil || findCode(errs, apierr.DuplicatePath) == nil {
		t.Fatalf("unexpected %s", errs.Error())
	}
}

func TestReservedPath(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/mcp","table":"a","properties":{"id":{"type":"integer","identifier":true}}}`})
	if e := expectOnly(t, errs, apierr.InvalidValue); e.Pointer != "/path" {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestWarnings(t *testing.T) {
	_, errs, warns := load(t, map[string]string{"orphan.query.ts": "export default (ctx) => ctx.sql`SELECT 1`;", "package.json": "{}", "types.d.ts": "declare const x: number;"})
	if len(errs) != 0 || findCode(warns, apierr.NoResources) == nil || findCode(warns, apierr.UnusedQueryFile) == nil {
		t.Fatalf("errs %s warns %s", errs.Error(), warns.Error())
	}
}

func TestDirNotFound(t *testing.T) {
	_, errs, _ := Load(filepath.Join(t.TempDir(), "nope"), script.NewEngine(time.Second))
	expectOnly(t, errs, apierr.DirNotFound)
}

func TestValidateInline(t *testing.T) {
	_, errs, _ := LoadInline("Book.json", []byte(`{"name":"Book","path":"/books","query":"book.query.ts","properties":{"id":{"type":"integer","identifier":true}}}`),
		func(name string) ([]byte, bool) {
			if name == "book.query.ts" {
				return []byte("export default (ctx) => ctx.sql`SELECT 1 AS id`;"), true
			}
			return nil, false
		}, script.NewEngine(time.Second))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
}

func TestXPrefixedHeaderParameterIsAWarning(t *testing.T) {
	def := `{
  "name": "Author",
  "path": "/authors",
  "table": "author",
  "parameters": {
    "X-Customer-Id": {"in": "header", "type": "integer"},
    "x-trace": {"in": "query"},
    "Customer-Id": {"in": "header"}
  },
  "operations": [{"method": "GET", "path": "/authors", "parameters": {"x-tenant": {"in": "header"}}}],
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "name": {"type": "string"}
  }
}`
	set, errs, warns := load(t, map[string]string{"Author.json": def})
	if len(errs) != 0 || len(set.Defs) != 1 {
		t.Fatalf("must stay valid and served: %s", errs.Error())
	}
	if len(warns) != 2 {
		t.Fatalf("want 2 warnings, got %s", warns.Error())
	}
	for i, want := range []struct{ pointer, name string }{{"/parameters/X-Customer-Id", "Customer-Id"}, {"/operations/0/parameters/x-tenant", "tenant"}} {
		w := warns[i]
		if w.Code != apierr.DeprecatedHeaderPrefix || w.Resource != "Author" || w.Pointer != want.pointer || !strings.Contains(w.Hint, "RFC 6648") || !strings.Contains(w.Hint, "name it "+want.name) || !strings.HasSuffix(w.HintText(), "see: caddy resource guide parameters") {
			t.Errorf("warning %d: %+v", i, w)
		}
	}
}
