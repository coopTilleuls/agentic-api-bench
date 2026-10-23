package script

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"math/big"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	starjson "go.starlark.net/lib/json"
	"go.starlark.net/starlark"
	"go.starlark.net/starlarkstruct"
	"go.starlark.net/syntax"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

// NewEngine returns the engine used by the server: Starlark for .star/.py
// files, goja (TypeScript/JavaScript) for everything else.
func NewEngine(timeout time.Duration) Engine {
	return &multiEngine{ts: NewGoja(timeout), star: NewStarlark(timeout)}
}

type multiEngine struct {
	ts, star Engine
}

func IsStarlark(filename string) bool {
	return strings.HasSuffix(filename, ".star") || strings.HasSuffix(filename, ".py")
}

func (m *multiEngine) EvalDefinition(filename string, src []byte) ([]byte, error) {
	return m.ts.EvalDefinition(filename, src)
}

func (m *multiEngine) Load(filename string, src []byte) (Module, error) {
	if IsStarlark(filename) {
		return m.star.Load(filename, src)
	}
	return m.ts.Load(filename, src)
}

type starlarkEngine struct {
	timeout time.Duration
}

func NewStarlark(timeout time.Duration) Engine {
	return &starlarkEngine{timeout: timeout}
}

var fileOptions = &syntax.FileOptions{Set: true, While: true, TopLevelControl: true, GlobalReassign: true, Recursion: true}

func (e *starlarkEngine) EvalDefinition(filename string, _ []byte) ([]byte, error) {
	return nil, &apierr.Error{Code: apierr.InvalidExport, Message: filename + ": definitions are .json or .ts files", Hint: "Write the definition as JSON or TypeScript; Starlark is for query and command scripts."}
}

type reflex struct {
	re   *regexp.Regexp
	hint string
}

// Python habits Starlark rejects, with what to write instead. The parser's
// own messages ("got import, want primary expression") do not teach that.
var reflexes = []reflex{
	{regexp.MustCompile(`^\s*import\s+json\b`), "Starlark has no import statement. JSON is built in: ctx.json.decode(text) and ctx.json.encode(value); HTTP responses also have res.json()."},
	{regexp.MustCompile(`^\s*(import\s|from\s+\S+\s+import\s)`), "Starlark has no import statement: everything is on ctx (ctx.sql, ctx.db, ctx.fetch, ctx.json, ctx.env). Remove the import."},
	{regexp.MustCompile(`^\s*class\s`), "Starlark has no classes: use dicts ({\"id\": 1}) and functions."},
	{regexp.MustCompile(`(^|[^A-Za-z0-9_"'])[fF][rR]?["']`), "Starlark has no f-strings: use \"%s by %s\" % (a, b), \"{} by {}\".format(a, b), or + with str()."},
	{regexp.MustCompile(`^\s*(try|except|finally)\b`), "Starlark has no exceptions (try/except): check values before using them (if x == None, if \"k\" in d), check res.status after ctx.fetch, and call ctx.reject(...) to refuse an input."},
	{regexp.MustCompile(`^\s*raise\b`), "Starlark has no raise: call ctx.reject(\"message\", pointer = \"/field\", hint = \"...\") to refuse an input with a 4xx error."},
	{regexp.MustCompile(`^\s*with\s`), "Starlark has no with statement: call the functions directly."},
	{regexp.MustCompile(`^\s*async\s+def\b|\bawait\b`), "Starlark has no async/await: ctx.fetch and ctx.db return their result directly."},
	{regexp.MustCompile(`\bis\s+(not\s+)?None\b`), "Starlark has no `is` operator: write == None or != None."},
	{regexp.MustCompile(`^\s*del\s`), "Starlark has no del statement: build a new dict or list instead (d.pop(\"k\") also works)."},
}

func checkReflexes(filename string, src []byte) error {
	inTriple := false
	for i, line := range strings.Split(string(src), "\n") {
		if strings.Count(line, `"""`)%2 == 1 || strings.Count(line, `'''`)%2 == 1 {
			inTriple = !inTriple
			continue
		}
		trimmed := strings.TrimSpace(line)
		if inTriple || strings.HasPrefix(trimmed, "#") {
			continue
		}
		for _, r := range reflexes {
			if r.re.MatchString(line) {
				return &apierr.Error{Code: apierr.TranspileError, Message: fmt.Sprintf("%s:%d: %q is not Starlark", filename, i+1, trimmed), Hint: r.hint}
			}
		}
	}
	return nil
}

func syntaxError(filename string, err error) *apierr.Error {
	msg := err.Error()
	var se syntax.Error
	if errors.As(err, &se) {
		msg = fmt.Sprintf("%s:%d:%d: %s", filename, se.Pos.Line, se.Pos.Col, se.Msg)
	}
	if !strings.HasPrefix(msg, filename) {
		msg = filename + ": " + msg
	}
	return &apierr.Error{Code: apierr.TranspileError, Message: msg, Hint: "Fix the Starlark syntax at the reported line:column. Starlark is a Python dialect without import, classes, exceptions, f-strings and `is`; indentation is significant."}
}

type starModule struct {
	engine   *starlarkEngine
	filename string
	globals  starlark.StringDict
	exports  []string
}

func (e *starlarkEngine) Load(filename string, src []byte) (Module, error) {
	if err := checkReflexes(filename, src); err != nil {
		return nil, err
	}
	thread := &starlark.Thread{Name: filename, Print: func(*starlark.Thread, string) {}}
	timer := time.AfterFunc(e.timeout, func() { thread.Cancel("timeout") })
	defer timer.Stop()
	globals, err := starlark.ExecFileOptions(fileOptions, thread, filename, src, nil)
	if err != nil {
		var evalErr *starlark.EvalError
		if errors.As(err, &evalErr) {
			return nil, e.runtimeError(filename, err)
		}
		return nil, syntaxError(filename, err)
	}
	m := &starModule{engine: e, filename: filename, globals: globals}
	for name, v := range globals {
		if _, ok := v.(*starlark.Function); !ok {
			continue
		}
		if name == "query" {
			name = "default"
		}
		m.exports = append(m.exports, name)
	}
	sort.Strings(m.exports)
	return m, nil
}

func (m *starModule) Exports() []string {
	return m.exports
}

func (e *starlarkEngine) runtimeError(filename string, err error) *apierr.Error {
	if ae, ok := apierr.As(err); ok {
		return ae
	}
	var evalErr *starlark.EvalError
	if errors.As(err, &evalErr) {
		msg := evalErr.Msg
		if strings.Contains(msg, "Starlark computation cancelled: timeout") {
			return &apierr.Error{Code: apierr.ScriptError, Message: fmt.Sprintf("%s: script exceeded the %s time limit", filename, e.timeout), Hint: "Scripts must return quickly: no long loops, and remote APIs called with ctx.fetch must answer fast."}
		}
		return &apierr.Error{Code: apierr.ScriptError, Message: filename + ": " + strings.TrimSpace(evalErr.Backtrace()), Hint: starHint(msg)}
	}
	return &apierr.Error{Code: apierr.ScriptError, Message: filename + ": " + err.Error(), Hint: starHint(err.Error())}
}

func starHint(msg string) string {
	switch {
	case strings.Contains(msg, "has no .") && strings.Contains(msg, "field or method"):
		return "Read the ctx API in the guide: ctx.sql, ctx.db.query/exec, ctx.fetch, ctx.json.decode/encode, ctx.env, ctx.reject, ctx.get/ctx.set, ctx.resource, ctx.request (method, path, body, json, accept, accepts, query, header), ctx.crypto, ctx.time, ctx.response, ctx.operation, ctx.phase, ctx.validating, ctx.id, ctx.page, ctx.items_per_page, ctx.filters."
	case strings.Contains(msg, "key") && strings.Contains(msg, "not in dict"):
		return "Use d.get(\"key\") or check `\"key\" in d` before reading a key that may be missing."
	}
	return "Fix the Starlark error. To refuse an input with a 4xx error, call ctx.reject(\"message\", pointer = \"/field\")."
}

func (m *starModule) Call(ctx context.Context, fn string, call Call) (any, error) {
	name := fn
	if fn == "default" {
		name = "query"
	}
	f, ok := m.globals[name].(*starlark.Function)
	if !ok {
		want := map[string]string{"query": "def query(ctx):", "handler": "def handler(value, ctx):", "create": "def create(input, ctx):", "update": "def update(input, ctx):", "remove": "def remove(input, ctx):", "delete": "def delete(input, ctx):"}[name]
		if want == "" {
			want = "def " + name + "(...):"
		}
		return nil, &apierr.Error{Code: apierr.InvalidExport, Message: fmt.Sprintf("%s does not define %s", m.filename, name), Hint: fmt.Sprintf("Add `%s` to %s.", want, m.filename)}
	}
	thread := &starlark.Thread{Name: m.filename, Print: func(*starlark.Thread, string) {}}
	timer := time.AfterFunc(m.engine.timeout, func() { thread.Cancel("timeout") })
	defer timer.Stop()
	stop := context.AfterFunc(ctx, func() { thread.Cancel("canceled") })
	defer stop()

	c := newStarContext(ctx, call)
	args := starlark.Tuple{c}
	switch fn {
	case "default":
	case HandlerFunc:
		args = starlark.Tuple{toStar(call.Value), c}
	default:
		args = starlark.Tuple{toStar(call.Input), c}
	}
	v, err := starlark.Call(thread, f, args, nil)
	if err != nil {
		return nil, m.engine.runtimeError(m.filename, err)
	}
	out, err := fromStar(v)
	if err != nil {
		return nil, &apierr.Error{Code: apierr.InvalidQueryResult, Message: fmt.Sprintf("%s: %s returned %s", m.filename, name, err), Hint: "Return a ctx.sql(...) fragment, a dict, a list of dicts, a scalar, or None."}
	}
	return out, nil
}

// responseValue wraps a raw Response as a Starlark value.
type responseValue struct{ *Response }

func (responseValue) String() string        { return "<response>" }
func (responseValue) Type() string          { return "response" }
func (responseValue) Freeze()               {}
func (responseValue) Truth() starlark.Bool  { return true }
func (responseValue) Hash() (uint32, error) { return 0, fmt.Errorf("unhashable: response") }

// fragment wraps sqlf.Fragment as a Starlark value.
type fragment struct{ f sqlf.Fragment }

func (fragment) String() string        { return "<sql>" }
func (fragment) Type() string          { return "sql" }
func (fragment) Freeze()               {}
func (fragment) Truth() starlark.Bool  { return true }
func (fragment) Hash() (uint32, error) { return 0, fmt.Errorf("unhashable: sql") }

var placeholder = regexp.MustCompile(`\$(\d+)`)

var named = regexp.MustCompile(`\{\{|\}\}|\{([A-Za-z_][A-Za-z0-9_]*)\}`)

// starSQL builds a fragment from "... {name} ..." with keyword values, or
// "... $1 ... $2" with positional values; a value may itself be a ctx.sql
// fragment, which is inlined. Values are always bound, never concatenated.
func starSQL(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
	if len(args) == 0 {
		return nil, fmt.Errorf("ctx.sql(text, name = value, ...): pass the SQL text with {name} placeholders, then one keyword argument per name")
	}
	text, ok := starlark.AsString(args[0])
	if !ok {
		return nil, fmt.Errorf("ctx.sql: first argument must be the SQL text, got %s", args[0].Type())
	}
	if len(kwargs) > 0 {
		if len(args) > 1 {
			return nil, fmt.Errorf("ctx.sql: use either {name} placeholders with keyword arguments or $1, $2 with positional values, not both")
		}
		return namedSQL(text, kwargs)
	}
	values := args[1:]
	locs := placeholder.FindAllStringSubmatchIndex(text, -1)
	strs := make([]string, 0, len(locs)+1)
	vals := make([]any, 0, len(locs))
	used := make([]bool, len(values))
	prev := 0
	for _, loc := range locs {
		n, _ := strconv.Atoi(text[loc[2]:loc[3]])
		if n < 1 || n > len(values) {
			return nil, fmt.Errorf("ctx.sql: $%d has no value (%d given); pass one value per placeholder: ctx.sql(\"... $1 ...\", value)", n, len(values))
		}
		v, err := starToParam(values[n-1])
		if err != nil {
			return nil, fmt.Errorf("ctx.sql: value for $%d: %v", n, err)
		}
		strs = append(strs, text[prev:loc[0]])
		vals = append(vals, v)
		used[n-1] = true
		prev = loc[1]
	}
	strs = append(strs, text[prev:])
	for i, u := range used {
		if !u {
			return nil, fmt.Errorf("ctx.sql: value #%d is not used by any $%d placeholder; values are bound with $1, $2..., never concatenated", i+1, i+1)
		}
	}
	return fragment{sqlf.Tagged(strs, vals)}, nil
}

func namedSQL(text string, kwargs []starlark.Tuple) (starlark.Value, error) {
	values := map[string]starlark.Value{}
	used := map[string]bool{}
	for _, kw := range kwargs {
		k, _ := starlark.AsString(kw[0])
		values[k] = kw[1]
	}
	var strs []string
	var vals []any
	var cur strings.Builder
	prev := 0
	for _, loc := range named.FindAllStringSubmatchIndex(text, -1) {
		cur.WriteString(text[prev:loc[0]])
		prev = loc[1]
		switch m := text[loc[0]:loc[1]]; m {
		case "{{":
			cur.WriteString("{")
			continue
		case "}}":
			cur.WriteString("}")
			continue
		}
		name := text[loc[2]:loc[3]]
		v, ok := values[name]
		if !ok {
			return nil, fmt.Errorf("ctx.sql: {%s} has no value; pass it as a keyword argument: ctx.sql(\"... {%s} ...\", %s = value). Write {{ and }} for literal braces", name, name, name)
		}
		p, err := starToParam(v)
		if err != nil {
			return nil, fmt.Errorf("ctx.sql: value for {%s}: %v", name, err)
		}
		used[name] = true
		strs = append(strs, cur.String())
		cur.Reset()
		vals = append(vals, p)
	}
	cur.WriteString(text[prev:])
	strs = append(strs, cur.String())
	for _, kw := range kwargs {
		k, _ := starlark.AsString(kw[0])
		if !used[k] {
			return nil, fmt.Errorf("ctx.sql: keyword argument %s is not used by any {%s} placeholder", k, k)
		}
	}
	return fragment{sqlf.Tagged(strs, vals)}, nil
}

func starToParam(v starlark.Value) (any, error) {
	if f, ok := v.(fragment); ok {
		return f.f, nil
	}
	g, err := fromStar(v)
	if err != nil {
		return nil, err
	}
	switch x := g.(type) {
	case nil, string, bool, int64, float64:
		return x, nil
	case []any:
		return convertArray(x)
	}
	return nil, fmt.Errorf("cannot bind a %s; bind strings, numbers, booleans, None, lists of those, or ctx.sql fragments", v.Type())
}

func fromStar(v starlark.Value) (any, error) {
	switch x := v.(type) {
	case starlark.NoneType:
		return nil, nil
	case starlark.Bool:
		return bool(x), nil
	case starlark.Int:
		if i, ok := x.Int64(); ok {
			return i, nil
		}
		f, _ := new(big.Float).SetInt(x.BigInt()).Float64()
		return f, nil
	case starlark.Float:
		f := float64(x)
		if f == math.Trunc(f) && math.Abs(f) < 1<<53 {
			return int64(f), nil
		}
		return f, nil
	case starlark.String:
		return string(x), nil
	case fragment:
		return x.f, nil
	case responseValue:
		return x.Response, nil
	case *starlark.List:
		out := make([]any, 0, x.Len())
		for i := range x.Len() {
			e, err := fromStar(x.Index(i))
			if err != nil {
				return nil, err
			}
			out = append(out, e)
		}
		return out, nil
	case starlark.Tuple:
		out := make([]any, 0, len(x))
		for _, el := range x {
			e, err := fromStar(el)
			if err != nil {
				return nil, err
			}
			out = append(out, e)
		}
		return out, nil
	case *starlark.Dict:
		out := make(map[string]any, x.Len())
		for _, item := range x.Items() {
			k, ok := starlark.AsString(item[0])
			if !ok {
				return nil, fmt.Errorf("a dict with a non-string key %s", item[0])
			}
			e, err := fromStar(item[1])
			if err != nil {
				return nil, err
			}
			out[k] = e
		}
		return out, nil
	case *starlarkstruct.Struct:
		d := starlark.StringDict{}
		x.ToStringDict(d)
		out := make(map[string]any, len(d))
		for k, val := range d {
			e, err := fromStar(val)
			if err != nil {
				return nil, err
			}
			out[k] = e
		}
		return out, nil
	}
	return nil, fmt.Errorf("a %s", v.Type())
}

func toStar(v any) starlark.Value {
	switch x := v.(type) {
	case nil:
		return starlark.None
	case bool:
		return starlark.Bool(x)
	case int:
		return starlark.MakeInt(x)
	case int64:
		return starlark.MakeInt64(x)
	case float64:
		return starlark.Float(x)
	case string:
		return starlark.String(x)
	case time.Time:
		return starlark.String(x.UTC().Format(time.RFC3339Nano))
	case []any:
		l := make([]starlark.Value, len(x))
		for i, e := range x {
			l[i] = toStar(e)
		}
		return starlark.NewList(l)
	case map[string]any:
		d := starlark.NewDict(len(x))
		keys := make([]string, 0, len(x))
		for k := range x {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			_ = d.SetKey(starlark.String(k), toStar(x[k]))
		}
		return d
	case map[string]string:
		m := make(map[string]any, len(x))
		for k, s := range x {
			m[k] = s
		}
		return toStar(m)
	case []map[string]any:
		l := make([]starlark.Value, len(x))
		for i, e := range x {
			l[i] = toStar(e)
		}
		return starlark.NewList(l)
	}
	b, err := json.Marshal(v)
	if err != nil {
		return starlark.None
	}
	var generic any
	_ = json.Unmarshal(b, &generic)
	return toStar(generic)
}

func starNames(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) ([]string, error) {
	var list starlark.Value = starlark.None
	if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 0, &list); err != nil {
		return nil, err
	}
	if list == starlark.None {
		return nil, nil
	}
	g, err := fromStar(list)
	items, ok := g.([]any)
	if err != nil || !ok {
		return nil, fmt.Errorf("%s: expected a list of property names, got %s", b.Name(), list.Type())
	}
	out := make([]string, 0, len(items))
	for _, it := range items {
		s, ok := it.(string)
		if !ok {
			return nil, fmt.Errorf("%s: property names are strings", b.Name())
		}
		out = append(out, s)
	}
	return out, nil
}

func starHelper(h SQLHelper) starlark.Value {
	frag := func(f sqlf.Fragment, err error) (starlark.Value, error) {
		if err != nil {
			return nil, err
		}
		return fragment{f}, nil
	}
	oneName := func(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (string, error) {
		var name string
		err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &name)
		return name, err
	}
	return starlarkstruct.FromStringDict(starlark.String("resource"), starlark.StringDict{
		"table": builtin("table", func(*starlark.Thread, *starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
			return fragment{h.Table()}, nil
		}),
		"alias": builtin("alias", func(*starlark.Thread, *starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
			return starlark.String(h.Alias()), nil
		}),
		"column": builtin("column", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			name, err := oneName(b, args, kwargs)
			if err != nil {
				return nil, err
			}
			return frag(h.Column(name))
		}),
		"columns": builtin("columns", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			names, err := starNames(b, args, kwargs)
			if err != nil {
				return nil, err
			}
			return frag(h.Columns(names))
		}),
		"json": builtin("json", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			names, err := starNames(b, args, kwargs)
			if err != nil {
				return nil, err
			}
			return frag(h.JSON(names))
		}),
		"identifier": builtin("identifier", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			var v starlark.Value
			if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &v); err != nil {
				return nil, err
			}
			p, err := starToParam(v)
			if err != nil {
				return nil, err
			}
			return fragment{h.Identifier(p)}, nil
		}),
		"join": builtin("join", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			name, err := oneName(b, args, kwargs)
			if err != nil {
				return nil, err
			}
			return frag(h.Join(name))
		}),
		"relation": builtin("relation", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			name, err := oneName(b, args, kwargs)
			if err != nil {
				return nil, err
			}
			sub, err := h.Relation(name)
			if err != nil {
				return nil, err
			}
			return starHelper(sub), nil
		}),
	})
}

func builtin(name string, fn func(thread *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error)) *starlark.Builtin {
	return starlark.NewBuiltin(name, fn)
}

func newStarContext(goctx context.Context, call Call) starlark.Value {
	req := call.Request
	host := call.Host
	need := func(what string) error {
		if host == nil {
			return fmt.Errorf("%s is not available here", what)
		}
		return nil
	}
	fragArg := func(what string, args starlark.Tuple) (sqlf.Fragment, error) {
		if len(args) != 1 {
			return sqlf.Fragment{}, fmt.Errorf("%s(sql) takes one ctx.sql(...) fragment", what)
		}
		f, ok := args[0].(fragment)
		if !ok {
			return sqlf.Fragment{}, fmt.Errorf("%s expects a ctx.sql(\"... $1\", value) fragment, not a %s; build statements with ctx.sql so values are bound as parameters", what, args[0].Type())
		}
		return f.f, nil
	}

	request := starlarkstruct.FromStringDict(starlark.String("request"), starlark.StringDict{
		"method": starlark.String(req.Method),
		"path":   starlark.String(req.Path),
		"accept": starlark.String(req.Header.Get("Accept")),
		"body":   starlark.String(req.Body),
		"json": builtin("ctx.request.json", func(*starlark.Thread, *starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
			v, err := requestJSON(req.Body)
			if err != nil {
				return nil, err
			}
			return toStar(v), nil
		}),
		"accepts": builtin("ctx.request.accepts", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			var typ string
			if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &typ); err != nil {
				return nil, err
			}
			return starlark.Bool(accepts(req.Header.Get("Accept"), typ)), nil
		}),
		"query": builtin("ctx.request.query", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			var name string
			if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &name); err != nil {
				return nil, err
			}
			if vals := req.Query[name]; len(vals) > 0 {
				return starlark.String(vals[0]), nil
			}
			return starlark.None, nil
		}),
		"header": builtin("ctx.request.header", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			var name string
			if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &name); err != nil {
				return nil, err
			}
			if vals := req.Header.Values(name); len(vals) > 0 {
				return starlark.String(vals[0]), nil
			}
			return starlark.None, nil
		}),
	})

	db := starlarkstruct.FromStringDict(starlark.String("db"), starlark.StringDict{
		"query": builtin("ctx.db.query", func(_ *starlark.Thread, _ *starlark.Builtin, args starlark.Tuple, _ []starlark.Tuple) (starlark.Value, error) {
			if err := need("ctx.db"); err != nil {
				return nil, err
			}
			f, err := fragArg("ctx.db.query", args)
			if err != nil {
				return nil, err
			}
			rows, err := host.Query(goctx, f)
			if err != nil {
				return nil, err
			}
			if rows == nil {
				rows = []map[string]any{}
			}
			return toStar(rows), nil
		}),
		"exec": builtin("ctx.db.exec", func(_ *starlark.Thread, _ *starlark.Builtin, args starlark.Tuple, _ []starlark.Tuple) (starlark.Value, error) {
			if err := need("ctx.db"); err != nil {
				return nil, err
			}
			f, err := fragArg("ctx.db.exec", args)
			if err != nil {
				return nil, err
			}
			n, err := host.Exec(goctx, f)
			if err != nil {
				return nil, err
			}
			return starlarkstruct.FromStringDict(starlark.String("result"), starlark.StringDict{"row_count": starlark.MakeInt64(n)}), nil
		}),
	})

	fetch := builtin("ctx.fetch", func(thread *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		if err := need("ctx.fetch"); err != nil {
			return nil, err
		}
		var u, method string
		var headers *starlark.Dict
		var body starlark.Value = starlark.None
		if err := starlark.UnpackArgs(b.Name(), args, kwargs, "url", &u, "method?", &method, "headers?", &headers, "body?", &body); err != nil {
			return nil, err
		}
		r := FetchRequest{URL: u, Method: "GET", Headers: map[string]string{}}
		if method != "" {
			r.Method = strings.ToUpper(method)
		}
		if headers != nil {
			for _, item := range headers.Items() {
				k, _ := starlark.AsString(item[0])
				v, _ := starlark.AsString(item[1])
				r.Headers[strings.ToLower(k)] = v
			}
		}
		switch x := body.(type) {
		case starlark.NoneType:
		case starlark.String:
			r.Body = string(x)
		default:
			g, err := fromStar(body)
			if err != nil {
				return nil, fmt.Errorf("ctx.fetch body: %v", err)
			}
			enc, _ := json.Marshal(g)
			r.Body = string(enc)
			if _, ok := r.Headers["content-type"]; !ok {
				r.Headers["content-type"] = "application/json"
			}
		}
		resp, err := host.Fetch(goctx, r)
		if err != nil {
			return nil, err
		}
		text := string(resp.Body)
		return starlarkstruct.FromStringDict(starlark.String("response"), starlark.StringDict{
			"status":  starlark.MakeInt(resp.Status),
			"ok":      starlark.Bool(resp.Status >= 200 && resp.Status < 300),
			"headers": toStar(resp.Headers),
			"text": builtin("response.text", func(*starlark.Thread, *starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
				return starlark.String(text), nil
			}),
			"json": builtin("response.json", func(*starlark.Thread, *starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
				var v any
				if err := json.Unmarshal(resp.Body, &v); err != nil {
					return nil, &apierr.Error{Code: apierr.FetchFailed, Message: fmt.Sprintf("response of %s %s is not JSON: %s", r.Method, r.URL, truncate(text, 120)), Hint: "Use res.text() for non-JSON responses, and check res.status first."}
				}
				return toStar(normalize(v)), nil
			}),
		}), nil
	})

	env := builtin("ctx.env", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		if err := need("ctx.env"); err != nil {
			return nil, err
		}
		var name string
		if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &name); err != nil {
			return nil, err
		}
		v, err := host.Env(name)
		if err != nil {
			return nil, err
		}
		return starlark.String(v), nil
	})

	reject := builtin("ctx.reject", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		var message, pointer, hint, code string
		status := 0
		if err := starlark.UnpackArgs(b.Name(), args, kwargs, "message?", &message, "pointer?", &pointer, "hint?", &hint, "code?", &code, "status?", &status); err != nil {
			return nil, err
		}
		e := &apierr.Error{Code: apierr.Rejected, Message: message, Pointer: pointer, Hint: hint, Rejected: true}
		if code != "" {
			e.Code = apierr.Code(code)
		}
		if status >= 400 && status < 600 {
			e.HTTPStatus = status
		}
		if e.Message == "" {
			e.Message = "the input was rejected"
		}
		return nil, e
	})

	values := call.Values
	if values == nil {
		values = map[string]any{}
	}
	get := builtin("ctx.get", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		var name string
		if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &name); err != nil {
			return nil, err
		}
		if f, ok := values[name].(sqlf.Fragment); ok {
			return fragment{f}, nil
		}
		return toStar(values[name]), nil
	})
	set := builtin("ctx.set", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		var name string
		var v starlark.Value
		if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 2, &name, &v); err != nil {
			return nil, err
		}
		g, err := fromStar(v)
		if err != nil {
			return nil, fmt.Errorf("ctx.set(%q): cannot store %v", name, err)
		}
		values[name] = g
		return starlark.None, nil
	})
	resource := builtin("ctx.resource", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		var name string
		if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &name); err != nil {
			return nil, err
		}
		if call.Resources == nil {
			return nil, fmt.Errorf("ctx.resource is not available here")
		}
		h, err := call.Resources.Resource(name)
		if err != nil {
			return nil, err
		}
		return starHelper(h), nil
	})

	property := builtin("ctx.property", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		var name string
		if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &name); err != nil {
			return nil, err
		}
		if call.Property == nil {
			return nil, &apierr.Error{Code: apierr.InvalidHelperCall, Message: "ctx.property is only available in parameter handlers", Hint: "In a query, reference your own SQL aliases; ctx.property names the columns of the query result for a handler's condition."}
		}
		f, err := call.Property(name)
		if err != nil {
			return nil, err
		}
		return fragment{f}, nil
	})

	response := builtin("ctx.response", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		if !call.RawAllowed {
			return nil, notAllowedResponse()
		}
		var fields map[string]any
		if len(args) == 1 && len(kwargs) == 0 {
			d, ok := args[0].(*starlark.Dict)
			if !ok {
				return nil, responseErr("expects a dict or keyword arguments, got %s", args[0].Type())
			}
			g, err := fromStar(d)
			if err != nil {
				return nil, responseErr("%v", err)
			}
			fields = g.(map[string]any)
		} else {
			var status starlark.Value = starlark.None
			var headers, body starlark.Value = starlark.None, starlark.None
			if err := starlark.UnpackArgs(b.Name(), args, kwargs, "status?", &status, "headers?", &headers, "body?", &body); err != nil {
				return nil, responseErr("%v", err)
			}
			fields = map[string]any{}
			for k, v := range map[string]starlark.Value{"status": status, "headers": headers, "body": body} {
				if v == starlark.None {
					continue
				}
				g, err := fromStar(v)
				if err != nil {
					return nil, responseErr("%s: %v", k, err)
				}
				fields[k] = g
			}
		}
		resp, err := buildResponse(fields)
		if err != nil {
			return nil, err
		}
		return responseValue{resp}, nil
	})

	fields := starlark.StringDict{
		"response":   response,
		"crypto":     starCrypto(),
		"time":       starTime(),
		"property":   property,
		"get":        get,
		"set":        set,
		"resource":   resource,
		"phase":      starlark.String(call.Phase),
		"validating": starlark.Bool(call.Validating),
		"sql":        builtin("ctx.sql", starSQL),
		"db":         db,
		"fetch":      fetch,
		"env":        env,
		"reject":     reject,
		"json":       starjson.Module,
		"request":    request,
		"operation":  starlark.String(call.Operation),
		"id":         toStar(call.ID),
	}
	if call.Operation == "collection" {
		filters := call.Filters
		if filters == nil {
			filters = map[string]any{}
		}
		fields["page"] = starlark.MakeInt(call.Page)
		fields["items_per_page"] = starlark.MakeInt(call.ItemsPerPage)
		fields["filters"] = toStar(filters)
	}
	return starlarkstruct.FromStringDict(starlark.String("ctx"), fields)
}

func starStr(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple, dst ...*string) error {
	vals := make([]any, len(dst))
	for i, d := range dst {
		vals[i] = d
	}
	if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, len(dst), vals...); err != nil {
		return &apierr.Error{Code: apierr.InvalidCryptoCall, Message: b.Name() + ": " + err.Error(), Hint: "Pass text (UTF-8 strings); convert with str(x) first."}
	}
	return nil
}

func starCrypto() starlark.Value {
	unwrap := func(v string, err *apierr.Error) (starlark.Value, error) {
		if err != nil {
			return nil, err
		}
		return starlark.String(v), nil
	}
	fns := starlark.StringDict{}
	add := func(fn func(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error), names ...string) {
		for _, n := range names {
			fns[n] = builtin("ctx.crypto."+n, func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
				return fn(b, args, kwargs)
			})
		}
	}
	for name := range hashAlgos {
		add(func(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			var data string
			if err := starStr(b, args, kwargs, &data); err != nil {
				return nil, err
			}
			return starlark.String(hashHex(name, data)), nil
		}, name)
	}
	add(func(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		var algo, key, data string
		if err := starStr(b, args, kwargs, &algo, &key, &data); err != nil {
			return nil, err
		}
		return unwrap(hmacHex(algo, key, data))
	}, "hmac")
	add(func(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		var a, c string
		if err := starStr(b, args, kwargs, &a, &c); err != nil {
			return nil, err
		}
		return starlark.Bool(constantTimeEqual(a, c)), nil
	}, "equal")
	add(func(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
		var n int64
		if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &n); err != nil {
			return nil, cryptoErr("%s expects a number of bytes: %v", b.Name(), err)
		}
		return unwrap(randomHex(n))
	}, "random_hex", "randomHex")
	add(func(*starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
		return starlark.String(newUUID()), nil
	}, "uuid")
	one := func(fn func(string) string) func(*starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
		return func(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			var data string
			if err := starStr(b, args, kwargs, &data); err != nil {
				return nil, err
			}
			return starlark.String(fn(data)), nil
		}
	}
	oneErr := func(fn func(string) (string, *apierr.Error)) func(*starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
		return func(b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			var data string
			if err := starStr(b, args, kwargs, &data); err != nil {
				return nil, err
			}
			return unwrap(fn(data))
		}
	}
	add(one(base64Encode), "base64_encode", "base64Encode")
	add(oneErr(base64Decode), "base64_decode", "base64Decode")
	add(one(hexEncode), "hex_encode", "hexEncode")
	add(oneErr(hexDecode), "hex_decode", "hexDecode")
	return starlarkstruct.FromStringDict(starlark.String("crypto"), fns)
}

func starTime() starlark.Value {
	return starlarkstruct.FromStringDict(starlark.String("time"), starlark.StringDict{
		"now": builtin("ctx.time.now", func(*starlark.Thread, *starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
			return starlark.String(timeNow()), nil
		}),
		"unix": builtin("ctx.time.unix", func(*starlark.Thread, *starlark.Builtin, starlark.Tuple, []starlark.Tuple) (starlark.Value, error) {
			return starlark.Float(timeUnix()), nil
		}),
		"parse": builtin("ctx.time.parse", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			var iso string
			if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &iso); err != nil {
				return nil, &apierr.Error{Code: apierr.InvalidTimeValue, Message: b.Name() + ": " + err.Error(), Hint: `Pass an ISO 8601 string such as "2024-01-31T12:00:00Z".`}
			}
			f, err := timeParse(iso)
			if err != nil {
				return nil, err
			}
			return starlark.Float(f), nil
		}),
		"format": builtin("ctx.time.format", func(_ *starlark.Thread, b *starlark.Builtin, args starlark.Tuple, kwargs []starlark.Tuple) (starlark.Value, error) {
			var seconds starlark.Value
			if err := starlark.UnpackPositionalArgs(b.Name(), args, kwargs, 1, &seconds); err != nil {
				return nil, &apierr.Error{Code: apierr.InvalidTimeValue, Message: b.Name() + ": " + err.Error(), Hint: "Pass a number of epoch seconds."}
			}
			f, ok := starlark.AsFloat(seconds)
			if !ok {
				return nil, &apierr.Error{Code: apierr.InvalidTimeValue, Message: "ctx.time.format expects a number of epoch seconds, got " + seconds.Type(), Hint: "Use ctx.time.unix() or ctx.time.parse(iso) to get one."}
			}
			out, err := timeFormat(f)
			if err != nil {
				return nil, err
			}
			return starlark.String(out), nil
		}),
	})
}
