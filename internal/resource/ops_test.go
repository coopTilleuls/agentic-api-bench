package resource

import (
	"fmt"
	"strings"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
)

const exportDef = `{"name":"AuthorReport","operations":[{"method":"GET","path":"/business/export","format":"csv","query":"report.query.ts"}],
 "properties":{"author":{"type":"string","filterable":true},"books":{"type":"integer"}}}`

const exportQuery = "export default (ctx) => ctx.sql`SELECT a.name AS author, count(b.id)::int AS books FROM author a LEFT JOIN book b ON b.author_id = a.id GROUP BY a.name ORDER BY a.name`;"

func TestLoadOperationsOnlyDefinition(t *testing.T) {
	set, errs, warns := load(t, map[string]string{"Report.json": exportDef, "report.query.ts": exportQuery})
	if len(errs) != 0 || len(warns) != 0 {
		t.Fatalf("%s %s", errs.Error(), warns.Error())
	}
	d := set.ByName("AuthorReport")
	if len(d.Ops) != 1 || d.Ops[0].Format != "csv" || d.Ops[0].QueryMod == nil || d.Operations() != nil {
		t.Fatalf("unexpected %+v", d)
	}
}

func TestOperationErrors(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"R.json": `{"name":"R","operations":[{"method":"GET","path":"/x"}],"properties":{"a":{"type":"string"}}}`})
	if e := expectOnly(t, errs, apierr.MissingSource); e.Pointer != "/operations/0" {
		t.Fatalf("unexpected %+v", e)
	}
	_, errs, _ = load(t, map[string]string{"R.json": `{"name":"R","operations":[{"method":"POST","path":"/x"}],"properties":{"a":{"type":"string"}}}`})
	if e := expectOnly(t, errs, apierr.MissingSource); !strings.Contains(e.Hint, "create") {
		t.Fatalf("unexpected %+v", e)
	}
	_, errs, _ = load(t, map[string]string{"R.json": `{"name":"R","operations":[{"method":"get","path":"/x","query":"r.query.ts"}],"properties":{"a":{"type":"string"}}}`, "r.query.ts": exportQuery})
	if e := expectOnly(t, errs, apierr.InvalidValue); e.Pointer != "/operations/0/method" || !strings.Contains(e.Hint, `"GET"`) {
		t.Fatalf("unexpected %+v", e)
	}
	_, errs, _ = load(t, map[string]string{
		"Author.json": authorJSON,
		"R.json":      `{"name":"R","operations":[{"method":"GET","path":"/authors","query":"r.query.ts"}],"properties":{"a":{"type":"string"}}}`, "r.query.ts": exportQuery,
	})
	expectOnly(t, errs, apierr.DuplicatePath)
}

func TestCheckOperationQuery(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{"Report.json": exportDef, "report.query.ts": exportQuery})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	_, errs = checkFiles(t, pool, map[string]string{"Report.json": exportDef, "report.query.ts": "export default (ctx) => ctx.sql`SELECT name AS author FROM author`;"})
	if e := expectOnly(t, errs, apierr.UnknownColumn); e.Pointer != "/properties/books" {
		t.Fatalf("unexpected %+v", e)
	}
}

const bookWithInput = `{"name":"Book","path":"/books","query":"book.query.ts","command":"book.command.ts",
 "input":{"title":{"type":"string","required":true},"author_id":{"type":"integer","required":true},"published":{"type":"boolean"}},
 "properties":{"id":{"type":"integer","identifier":true},"title":{"type":"string"},"author":{"type":"string","filterable":true},"published":{"type":"boolean"}}}`

func TestInputBlock(t *testing.T) {
	set, errs, _ := load(t, map[string]string{"Book.json": bookWithInput, "book.query.ts": bookWritableQuery, "book.command.ts": bookCommand})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	d := set.ByName("Book")
	var names []string
	for _, p := range d.InputProps() {
		names = append(names, p.Name)
	}
	if strings.Join(names, ",") != "title,author_id,published" {
		t.Fatalf("input order: %v", names)
	}
	in, verrs := ValidateInput(d, ModeCreate, map[string]any{"title": "x", "author_id": float64(1)}, nil)
	if len(verrs) != 0 || in["author_id"] != int64(1) {
		t.Fatalf("unexpected %v %s", in, verrs.Error())
	}
	_, verrs = ValidateInput(d, ModeCreate, map[string]any{"title": "x", "author_id": float64(1), "author": "y"}, nil)
	if len(verrs) != 1 || verrs[0].Code != apierr.UnknownProperty || !strings.Contains(verrs[0].Hint, "title, author_id, published") {
		t.Fatalf("unexpected %s", verrs.Error())
	}
}

func TestInputBlockSchema(t *testing.T) {
	_, errs, _ := load(t, map[string]string{"A.json": `{"name":"A","path":"/a","table":"a","input":{"x":{"type":"string","identifier":true}},"properties":{"id":{"type":"integer","identifier":true}}}`})
	if e := expectOnly(t, errs, apierr.UnknownField); e.Pointer != "/input/x/identifier" || !strings.Contains(e.Hint, "required") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestOperationInput(t *testing.T) {
	set, errs, _ := load(t, map[string]string{
		"R.json":       `{"name":"R","operations":[{"method":"POST","path":"/r","command":"r.command.ts","input":{"note":{"type":"string","required":true}}}],"properties":{"a":{"type":"string"}}}`,
		"r.command.ts": "export function create(input) { return { a: input.note }; }",
	})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	d := set.ByName("R")
	od := d.ForOperation(d.Ops[0])
	if _, verrs := ValidateInput(od, ModeCreate, map[string]any{"note": "x"}, nil); len(verrs) != 0 {
		t.Fatal(verrs.Error())
	}
}

func TestTableInputMustBeColumns(t *testing.T) {
	pool := testPool(t)
	_, errs := checkFiles(t, pool, map[string]string{"Author.json": `{"name":"Author","path":"/authors","table":"author","input":{"full_name":{"type":"string"}},"properties":{"id":{"type":"integer","identifier":true},"name":{"type":"string"}}}`})
	if e := expectOnly(t, errs, apierr.UnknownColumn); e.Pointer != "/input/full_name" || !strings.Contains(e.Hint, "command") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestBrokenDefinitionsKeepTheirPaths(t *testing.T) {
	set, errs, _ := load(t, map[string]string{
		"Author.json": authorJSON,
		"Book.json":   `{"name":"Book","path":"/books","table":"book","properties":{"id":{"type":"integer"}}}`,
		"Bad.json":    `{"name":"Bad","path":"/bad","tabel":"x","properties":{"id":{"type":"integer","identifier":true}}}`,
		"Report.json": `{"name":"Report","operations":[{"method":"GET","path":"/report"}],"properties":{"a":{"type":"string"}}}`,
		"Broken.json": `{"name":`,
	})
	if len(errs) != 4 || len(set.Defs) != 1 || set.Defs[0].Name != "Author" {
		t.Fatalf("errs %s defs %v", errs.Error(), set.Defs)
	}
	paths := map[string][]string{}
	for _, b := range set.Broken {
		paths[b.File] = b.Paths
		if len(b.Errors) == 0 {
			t.Fatalf("broken %s without errors", b.File)
		}
	}
	want := map[string][]string{"Bad.json": {"/bad"}, "Book.json": {"/books"}, "Broken.json": nil, "Report.json": {"/report"}}
	if fmt.Sprint(paths) != fmt.Sprint(want) {
		t.Fatalf("got %v", paths)
	}
}

func TestDemoteMovesDefinitionsWithDatabaseErrors(t *testing.T) {
	set, _, _ := load(t, map[string]string{"Author.json": authorJSON, "Report.json": exportDef, "report.query.ts": exportQuery})
	set.Demote(apierr.List{{Code: apierr.UnknownTable, Resource: "Author", Pointer: "/table", Message: "m"}})
	if len(set.Defs) != 1 || set.Defs[0].Name != "AuthorReport" || len(set.Broken) != 1 || set.Broken[0].Paths[0] != "/authors" {
		t.Fatalf("unexpected %+v %+v", set.Defs, set.Broken)
	}
	set.Demote(apierr.List{{Code: apierr.DatabaseUnreachable, Message: "down"}})
	if len(set.Defs) != 0 || len(set.Broken) != 2 {
		t.Fatalf("database errors demote everything: %+v", set.Broken)
	}
}

func TestOperationPathWithIdentifierInTheMiddle(t *testing.T) {
	const cmd = "export function create(input, ctx) { return null; }"
	def := func(path, extra string) map[string]string {
		return map[string]string{
			"R.json":       `{"name":"R","operations":[{"method":"POST","path":"` + path + `","command":"r.command.ts"` + extra + `}],"properties":{"id":{"type":"integer","identifier":true}}}`,
			"r.command.ts": cmd,
		}
	}
	set, errs, _ := load(t, def("/purchases/{id}/refund", `,"input":{}`))
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	op := set.ByName("R").Ops[0]
	if !op.IsItem() || op.Prefix() != "/purchases" || op.Suffix() != "/refund" || op.Resolve("7") != "/purchases/7/refund" {
		t.Fatalf("%+v prefix %q suffix %q", op, op.Prefix(), op.Suffix())
	}
	if _, verrs := ValidateInput(set.ByName("R").ForOperation(op), ModeCreate, map[string]any{"anything": 1}, nil); len(verrs) != 0 {
		t.Fatalf("an empty input ignores the body: %s", verrs.Error())
	}

	for path, pointer := range map[string]string{
		"/purchases/{slug}/refund":   "/operations/0/path",
		"/purchases/{id}/{id}":       "/operations/0/path",
		"/{id}/purchases/{id}":       "/operations/0/path",
		"/purchases/{id}refund":      "/operations/0/path",
		"/purchases/x{id}/refund":    "/operations/0/path",
		"/purchases/{id}/refund/":    "/operations/0/path",
		"/purchases/{id}/{other}/up": "/operations/0/path",
	} {
		_, errs, _ := load(t, def(path, ""))
		if e := findCode(errs, apierr.InvalidValue); e == nil || e.Pointer != pointer {
			t.Errorf("%s: got %s", path, errs.Error())
		}
	}
}

const multiProps = `"properties":{"a":{"type":"string"}}`

func multiDef(op string) string {
	return `{"name":"R","operations":[` + op + `],` + multiProps + `}`
}

func TestLoadMultiMethodQueryOperation(t *testing.T) {
	set, errs, warns := load(t, map[string]string{
		"R.json": multiDef(`{"method":["GET","POST"],"path":"/x","query":"r.query.ts"}`), "r.query.ts": exportQuery,
	})
	if len(errs) != 0 || len(warns) != 0 {
		t.Fatalf("%s %s", errs.Error(), warns.Error())
	}
	ops := set.ByName("R").Ops
	if len(ops) != 2 || ops[0].Method != "GET" || ops[1].Method != "POST" || !ops[0].Reads() || !ops[1].Reads() || ops[0].QueryMod == nil || ops[1].QueryMod == nil {
		t.Fatalf("unexpected %+v", ops)
	}
}

func TestLoadMultiMethodCommandOperation(t *testing.T) {
	set, errs, _ := load(t, map[string]string{
		"R.json":       multiDef(`{"method":["POST","PUT","DELETE"],"path":"/x","command":"r.command.ts"}`),
		"r.command.ts": "export function create(i, ctx) {}\nexport function update(i, ctx) {}\nexport function remove(i, ctx) {}",
	})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	ops := set.ByName("R").Ops
	if len(ops) != 3 || ops[0].Reads() || ops[1].Reads() || ops[2].Reads() || ops[2].Method != "DELETE" {
		t.Fatalf("unexpected %+v", ops)
	}
}

func TestSingleMethodOperationIsNotMulti(t *testing.T) {
	set, errs, _ := load(t, map[string]string{"R.json": multiDef(`{"method":"GET","path":"/x","query":"r.query.ts"}`), "r.query.ts": exportQuery})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	if op := set.ByName("R").Ops[0]; op.Multi || !op.Reads() {
		t.Fatalf("unexpected %+v", op)
	}
}

func TestMultiMethodOperationErrors(t *testing.T) {
	const topic = "see: caddy resource guide operations"
	command := "export function create(i, ctx) {}\nexport function update(i, ctx) {}"
	cases := []struct {
		name  string
		files map[string]string
		code  apierr.Code
		ptr   string
		hint  string
	}{
		{"no handler", map[string]string{"R.json": multiDef(`{"method":["GET","POST"],"path":"/x"}`)}, apierr.MissingSource, "/operations/0", "differs per method"},
		{"definition handler is not enough", map[string]string{"R.json": `{"name":"R","query":"r.query.ts","operations":[{"method":["GET","POST"],"path":"/x"}],` + multiProps + `}`, "r.query.ts": exportQuery}, apierr.MissingSource, "/operations/0", "differs per method"},
		{"duplicate in array", map[string]string{"R.json": multiDef(`{"method":["GET","GET"],"path":"/x","query":"r.query.ts"}`), "r.query.ts": exportQuery}, apierr.InvalidValue, "/operations/0/method", "array"},
		{"empty array", map[string]string{"R.json": multiDef(`{"method":[],"path":"/x","query":"r.query.ts"}`), "r.query.ts": exportQuery}, apierr.InvalidValue, "/operations/0/method", "array"},
		{"unknown method in array", map[string]string{"R.json": multiDef(`{"method":["GET","post"],"path":"/x","query":"r.query.ts"}`), "r.query.ts": exportQuery}, apierr.InvalidValue, "/operations/0/method/1", `"POST"`},
		{"pair declared twice via arrays", map[string]string{"R.json": multiDef(`{"method":["GET","POST"],"path":"/x","query":"r.query.ts"},{"method":"POST","path":"/x","command":"r.command.ts"}`), "r.query.ts": exportQuery, "r.command.ts": command}, apierr.DuplicatePath, "/operations/1", "unique"},
		{"command cannot serve GET", map[string]string{"R.json": multiDef(`{"method":["GET","POST"],"path":"/x","command":"r.command.ts"}`), "r.command.ts": command}, apierr.InvalidValue, "/operations/0/method", "query"},
		{"command misses a function", map[string]string{"R.json": multiDef(`{"method":["POST","DELETE"],"path":"/x","command":"r.command.ts"}`), "r.command.ts": command}, apierr.OperationNotSupported, "/operations/0/command", "remove"},
		{"both handlers", map[string]string{"R.json": multiDef(`{"method":["GET","POST"],"path":"/x","query":"r.query.ts","command":"r.command.ts"}`), "r.query.ts": exportQuery, "r.command.ts": command}, apierr.InvalidValue, "/operations/0", "one handler"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			_, errs, _ := load(t, c.files)
			e := expectOnly(t, errs, c.code)
			if e.Pointer != c.ptr || !strings.Contains(e.HintText(), c.hint) || !strings.Contains(e.HintText(), topic) {
				t.Fatalf("unexpected %+v", e)
			}
		})
	}
}

func TestMultiMethodPairDeclaredInTwoDefinitions(t *testing.T) {
	_, errs, _ := load(t, map[string]string{
		"A.json":     `{"name":"A","operations":[{"method":["GET","POST"],"path":"/x","query":"r.query.ts"}],` + multiProps + `}`,
		"B.json":     `{"name":"B","operations":[{"method":"POST","path":"/x","command":"r.command.ts"}],` + multiProps + `}`,
		"r.query.ts": exportQuery, "r.command.ts": "export function create(i, ctx) {}",
	})
	if e := findCode(errs, apierr.DuplicatePath); e == nil || !strings.Contains(e.HintText(), "see: caddy resource guide operations") {
		t.Fatalf("unexpected %s", errs.Error())
	}
}
