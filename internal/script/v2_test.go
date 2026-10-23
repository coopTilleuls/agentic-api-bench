package script

import (
	"context"
	"net/http"
	"reflect"
	"testing"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

type fakeResources struct{}

type fakeHelper struct{ alias string }

func (fakeResources) Resource(name string) (SQLHelper, error) {
	if name != "Book" {
		return nil, &apierr.Error{Code: apierr.InvalidHelperCall, Message: "no such resource " + name}
	}
	return fakeHelper{"book"}, nil
}

func (h fakeHelper) Table() sqlf.Fragment { return sqlf.Raw(`"book" AS "` + h.alias + `"`) }
func (h fakeHelper) Alias() string        { return h.alias }
func (h fakeHelper) Column(n string) (sqlf.Fragment, error) {
	return sqlf.Raw(`"` + h.alias + `"."` + n + `"`), nil
}
func (h fakeHelper) Columns(names []string) (sqlf.Fragment, error) {
	if names == nil {
		return sqlf.Raw("ALL"), nil
	}
	out := ""
	for i, n := range names {
		if i > 0 {
			out += ", "
		}
		out += n
	}
	return sqlf.Raw(out), nil
}
func (h fakeHelper) Identifier(v any) sqlf.Fragment {
	return sqlf.Tagged([]string{`"` + h.alias + `"."id" = `, ""}, []any{v})
}
func (h fakeHelper) Join(rel string) (sqlf.Fragment, error) {
	return sqlf.Raw("JOIN " + rel), nil
}
func (h fakeHelper) Relation(name string) (SQLHelper, error) {
	return fakeHelper{h.alias + "_" + name}, nil
}
func (h fakeHelper) JSON(names []string) (sqlf.Fragment, error) {
	return sqlf.Raw("json"), nil
}

var bothEngines = []struct{ file, query, handler, getset, reject string }{
	{
		file: "x.ts",
		query: "export default function query(ctx) {\n" +
			"  const b = ctx.resource('Book');\n" +
			"  const a = b.relation('author');\n" +
			"  return ctx.sql`SELECT ${b.columns()}, ${a.columns(['id', 'name'])} FROM ${b.table()} JOIN ${a.table()} ON ${b.join('author')} WHERE ${b.identifier(7)} AND ${b.column('published')} AND ${a.alias()} = ${ctx.phase} AND ${ctx.validating}`;\n" +
			"}",
		handler: "export default function handler(value, ctx) { ctx.set('customer', value + 1); return value > 1 ? ctx.sql`stock > ${value}` : null; }",
		getset:  "export default function query(ctx) { ctx.set('n', {a: [1, 2]}); return ctx.sql`SELECT ${ctx.get('customer')}, ${ctx.get('missing')}, ${ctx.get('n').a[1]}`; }",
		reject:  "export default function handler(value, ctx) { ctx.reject({message: 'unknown customer'}); }",
	},
	{
		file: "x.star",
		query: "def query(ctx):\n" +
			"    b = ctx.resource('Book')\n" +
			"    a = b.relation('author')\n" +
			"    return ctx.sql('SELECT {bc}, {ac} FROM {bt} JOIN {at} ON {j} WHERE {id} AND {pub} AND {alias} = {phase} AND {v}', bc = b.columns(), ac = a.columns(['id', 'name']), bt = b.table(), at = a.table(), j = b.join('author'), id = b.identifier(7), pub = b.column('published'), alias = a.alias(), phase = ctx.phase, v = ctx.validating)\n",
		handler: "def handler(value, ctx):\n    ctx.set('customer', value + 1)\n    if value > 1:\n        return ctx.sql('stock > {v}', v = value)\n    return None\n",
		getset:  "def query(ctx):\n    ctx.set('n', {'a': [1, 2]})\n    return ctx.sql('SELECT {c}, {m}, {n}', c = ctx.get('customer'), m = ctx.get('missing'), n = ctx.get('n')['a'][1])\n",
		reject:  "def handler(value, ctx):\n    ctx.reject('unknown customer')\n",
	},
}

func loadBoth(t *testing.T, file, src string) Module {
	t.Helper()
	m, err := NewEngine(time.Second).Load(file, []byte(src))
	if err != nil {
		t.Fatal(err)
	}
	return m
}

func TestResourceHelpersInBothLanguages(t *testing.T) {
	for _, e := range bothEngines {
		t.Run(e.file, func(t *testing.T) {
			v, err := loadBoth(t, e.file, e.query).Call(context.Background(), "default", Call{Request: Request{Header: http.Header{}}, Phase: "lookup", Validating: true, Resources: fakeResources{}})
			if err != nil {
				t.Fatal(err)
			}
			sql, args := v.(sqlf.Fragment).Render(0)
			want := `SELECT ALL, id, name FROM "book" AS "book" JOIN "book" AS "book_author" ON JOIN author WHERE "book"."id" = $1 AND "book"."published" AND $2 = $3 AND $4`
			if sql != want || !reflect.DeepEqual(args, []any{int64(7), "book_author", "lookup", true}) {
				t.Fatalf("got %q %v", sql, args)
			}
		})
	}
}

func TestHandlerReceivesValueAndSetsValues(t *testing.T) {
	for _, e := range bothEngines {
		t.Run(e.file, func(t *testing.T) {
			m := loadBoth(t, e.file, e.handler)
			values := map[string]any{}
			v, err := m.Call(context.Background(), HandlerFunc, Call{Request: Request{Header: http.Header{}}, Value: int64(5), Values: values})
			if err != nil {
				t.Fatal(err)
			}
			sql, args := v.(sqlf.Fragment).Render(0)
			if sql != "stock > $1" || !reflect.DeepEqual(args, []any{int64(5)}) || values["customer"] != int64(6) {
				t.Fatalf("got %q %v %v", sql, args, values)
			}
			v, err = m.Call(context.Background(), HandlerFunc, Call{Request: Request{Header: http.Header{}}, Value: int64(1), Values: values})
			if err != nil || v != nil {
				t.Fatalf("null handler result: %v %v", v, err)
			}
		})
	}
}

func TestGetAndSet(t *testing.T) {
	for _, e := range bothEngines {
		t.Run(e.file, func(t *testing.T) {
			v, err := loadBoth(t, e.file, e.getset).Call(context.Background(), "default", Call{Request: Request{Header: http.Header{}}, Values: map[string]any{"customer": int64(3)}})
			if err != nil {
				t.Fatal(err)
			}
			_, args := v.(sqlf.Fragment).Render(0)
			if !reflect.DeepEqual(args, []any{int64(3), nil, int64(2)}) {
				t.Fatalf("got %#v", args)
			}
		})
	}
}

func TestRejectIsMarkedAndKeepsStatusUnset(t *testing.T) {
	for _, e := range bothEngines {
		t.Run(e.file, func(t *testing.T) {
			_, err := loadBoth(t, e.file, e.reject).Call(context.Background(), HandlerFunc, Call{Request: Request{Header: http.Header{}}, Value: "x"})
			ae, ok := apierr.As(err)
			if !ok || !ae.Rejected || ae.HTTPStatus != 0 || ae.Status() != 422 || ae.Message != "unknown customer" {
				t.Fatalf("got %#v", err)
			}
		})
	}
}

func TestUnknownResourceIsHelperError(t *testing.T) {
	for _, src := range []struct{ file, src string }{
		{"x.ts", "export default (ctx) => ctx.resource('Nope').table();"},
		{"x.star", "def query(ctx):\n    return ctx.resource('Nope').table()\n"},
	} {
		_, err := loadBoth(t, src.file, src.src).Call(context.Background(), "default", Call{Request: Request{Header: http.Header{}}, Resources: fakeResources{}})
		if ae, ok := apierr.As(err); !ok || ae.Code != apierr.InvalidHelperCall {
			t.Fatalf("%s: got %v", src.file, err)
		}
	}
}
