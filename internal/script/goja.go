package script

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"sort"
	"strings"
	"time"

	"github.com/dop251/goja"
	"github.com/evanw/esbuild/pkg/api"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

type gojaEngine struct {
	timeout time.Duration
}

func NewGoja(timeout time.Duration) Engine {
	return &gojaEngine{timeout: timeout}
}

func transpile(filename string, src []byte) (string, error) {
	loader := api.LoaderTS
	if strings.HasSuffix(filename, ".js") {
		loader = api.LoaderJS
	}
	res := api.Transform(string(src), api.TransformOptions{
		Loader:     loader,
		Format:     api.FormatCommonJS,
		Target:     api.ES2020,
		Sourcefile: filename,
	})
	if len(res.Errors) > 0 {
		msgs := make([]string, 0, len(res.Errors))
		for _, m := range res.Errors {
			if m.Location != nil {
				msgs = append(msgs, fmt.Sprintf("%s:%d:%d: %s (%q)", filename, m.Location.Line, m.Location.Column+1, m.Text, strings.TrimSpace(m.Location.LineText)))
			} else {
				msgs = append(msgs, fmt.Sprintf("%s: %s", filename, m.Text))
			}
		}
		return "", &apierr.Error{Code: apierr.TranspileError, Message: strings.Join(msgs, "; "), Hint: "Fix the TypeScript syntax error at the reported line:column."}
	}
	return string(res.Code), nil
}

func compile(filename string, src []byte) (*goja.Program, error) {
	js, err := transpile(filename, src)
	if err != nil {
		return nil, err
	}
	prog, err := goja.Compile(filename, js, false)
	if err != nil {
		return nil, &apierr.Error{Code: apierr.TranspileError, Message: filename + ": " + err.Error(), Hint: "The transpiled JavaScript does not compile; avoid generators and other unsupported features."}
	}
	return prog, nil
}

// state carries host errors across the JavaScript boundary: a thrown host
// error keeps an index into errs, so it can be recovered intact even after a
// promise rejection or a catch/rethrow in the script.
type state struct {
	rt   *goja.Runtime
	errs []*apierr.Error
}

func (s *state) throw(e *apierr.Error) {
	obj := s.rt.NewGoError(fmt.Errorf("%s", e.Message))
	_ = obj.Set("__hostError", len(s.errs))
	_ = obj.Set("code", string(e.Code))
	s.errs = append(s.errs, e)
	panic(obj)
}

func (s *state) throwf(format string, args ...any) {
	panic(s.rt.NewTypeError(fmt.Sprintf(format, args...)))
}

func (g *gojaEngine) newRuntime() *state {
	rt := goja.New()
	s := &state{rt: rt}
	host := rt.NewObject()
	_ = host.Set("defineResource", func(v goja.Value) goja.Value { return v })
	_ = rt.Set("defineResource", host.Get("defineResource"))
	module := rt.NewObject()
	exports := rt.NewObject()
	_ = module.Set("exports", exports)
	_ = rt.Set("module", module)
	_ = rt.Set("exports", exports)
	_ = rt.Set("require", func(name string) goja.Value {
		if name == HostModule {
			return host
		}
		s.throwf("cannot import %q: scripts cannot import modules. defineResource is a global and everything else is on ctx; the only importable module is %q", name, HostModule)
		return nil
	})
	return s
}

func (g *gojaEngine) run(ctx context.Context, s *state, filename string, fn func() (goja.Value, error)) (goja.Value, error) {
	timer := time.AfterFunc(g.timeout, func() { s.rt.Interrupt("timeout") })
	defer timer.Stop()
	stop := context.AfterFunc(ctx, func() { s.rt.Interrupt("canceled") })
	defer stop()
	v, err := fn()
	if err != nil {
		return nil, g.scriptError(s, filename, err)
	}
	if p, ok := v.Export().(*goja.Promise); ok {
		switch p.State() {
		case goja.PromiseStateFulfilled:
			return p.Result(), nil
		case goja.PromiseStateRejected:
			return nil, g.fromValue(s, filename, p.Result())
		default:
			return nil, &apierr.Error{Code: apierr.ScriptError, Message: filename + ": the returned promise never settled", Hint: "Only await ctx.fetch, ctx.db and values; timers and other asynchronous APIs do not exist."}
		}
	}
	return v, nil
}

func (g *gojaEngine) fromValue(s *state, filename string, v goja.Value) *apierr.Error {
	if obj, ok := v.(*goja.Object); ok {
		if idx := obj.Get("__hostError"); idx != nil && !goja.IsUndefined(idx) {
			if i := int(idx.ToInteger()); i >= 0 && i < len(s.errs) {
				return s.errs[i]
			}
		}
		if stack := obj.Get("stack"); stack != nil && !goja.IsUndefined(stack) {
			return scriptErr(filename, stack.String())
		}
	}
	return scriptErr(filename, v.String())
}

func scriptErr(filename, msg string) *apierr.Error {
	return &apierr.Error{Code: apierr.ScriptError, Message: filename + ": " + msg, Hint: "Fix the JavaScript error. Only defineResource and the ctx argument are available; there is no Node.js API. To refuse an input with a 4xx error, call ctx.reject({message, pointer, hint})."}
}

func (g *gojaEngine) scriptError(s *state, filename string, err error) *apierr.Error {
	if e, ok := apierr.As(err); ok {
		return e
	}
	if _, ok := err.(*goja.InterruptedError); ok {
		return &apierr.Error{Code: apierr.ScriptError, Message: fmt.Sprintf("%s: script exceeded the %s time limit", filename, g.timeout), Hint: "Scripts must return quickly: no long loops, and remote APIs called with ctx.fetch must answer fast."}
	}
	if ex, ok := err.(*goja.Exception); ok {
		return g.fromValue(s, filename, ex.Value())
	}
	return scriptErr(filename, err.Error())
}

func moduleExports(rt *goja.Runtime) *goja.Object {
	exports := rt.Get("module").ToObject(rt).Get("exports")
	if exports == nil || goja.IsUndefined(exports) || goja.IsNull(exports) {
		return nil
	}
	return exports.ToObject(rt)
}

func (g *gojaEngine) EvalDefinition(filename string, src []byte) ([]byte, error) {
	prog, err := compile(filename, src)
	if err != nil {
		return nil, err
	}
	s := g.newRuntime()
	rt := s.rt
	v, err := g.run(context.Background(), s, filename, func() (goja.Value, error) {
		if _, err := rt.RunProgram(prog); err != nil {
			return nil, err
		}
		var def goja.Value
		if ex := moduleExports(rt); ex != nil {
			def = ex.Get("default")
		}
		if def == nil || goja.IsUndefined(def) {
			return nil, &apierr.Error{Code: apierr.InvalidExport, Message: filename + ": no default export"}
		}
		if _, isFn := goja.AssertFunction(def); isFn {
			return nil, &apierr.Error{Code: apierr.InvalidExport, Message: filename + ": the default export is a function, not a definition object"}
		}
		stringify, _ := goja.AssertFunction(rt.Get("JSON").ToObject(rt).Get("stringify"))
		return stringify(goja.Undefined(), def)
	})
	if err != nil {
		return nil, err
	}
	if goja.IsUndefined(v) {
		return nil, &apierr.Error{Code: apierr.InvalidExport, Message: filename + ": default export is not an object"}
	}
	return []byte(v.String()), nil
}

type gojaModule struct {
	engine   *gojaEngine
	filename string
	prog     *goja.Program
	exports  []string
}

func (g *gojaEngine) Load(filename string, src []byte) (Module, error) {
	prog, err := compile(filename, src)
	if err != nil {
		return nil, err
	}
	m := &gojaModule{engine: g, filename: filename, prog: prog}
	s := g.newRuntime()
	if _, err := g.run(context.Background(), s, filename, func() (goja.Value, error) {
		if _, err := s.rt.RunProgram(prog); err != nil {
			return nil, err
		}
		if ex := moduleExports(s.rt); ex != nil {
			for _, k := range ex.Keys() {
				if _, ok := goja.AssertFunction(ex.Get(k)); ok {
					m.exports = append(m.exports, k)
				}
			}
		}
		return goja.Undefined(), nil
	}); err != nil {
		return nil, err
	}
	sort.Strings(m.exports)
	return m, nil
}

func (m *gojaModule) Exports() []string {
	return m.exports
}

func (m *gojaModule) Call(ctx context.Context, fn string, call Call) (any, error) {
	s := m.engine.newRuntime()
	rt := s.rt
	v, err := m.engine.run(ctx, s, m.filename, func() (goja.Value, error) {
		if _, err := rt.RunProgram(m.prog); err != nil {
			return nil, err
		}
		var f goja.Callable
		if ex := moduleExports(rt); ex != nil {
			name := fn
			if fn == HandlerFunc && ex.Get(HandlerFunc) == nil {
				name = "default"
			}
			f, _ = goja.AssertFunction(ex.Get(name))
		}
		if f == nil {
			name := fn
			if fn == "default" {
				name = "a default"
			}
			return nil, &apierr.Error{Code: apierr.InvalidExport, Message: fmt.Sprintf("%s does not export %s function", m.filename, name)}
		}
		c := newContext(ctx, s, call)
		if fn == "default" {
			return f(goja.Undefined(), c)
		}
		if fn == HandlerFunc {
			return f(goja.Undefined(), toJS(rt, call.Value), c)
		}
		return f(goja.Undefined(), toJS(rt, call.Input), c)
	})
	if err != nil {
		return nil, err
	}
	return normalize(v.Export()), nil
}

// toJS converts Go data to native JavaScript values (plain objects/arrays)
// rather than reflection wrappers, so scripts can mutate and spread them.
func toJS(rt *goja.Runtime, v any) goja.Value {
	if v == nil {
		return goja.Null()
	}
	b, err := json.Marshal(v)
	if err != nil {
		return goja.Null()
	}
	parse, _ := goja.AssertFunction(rt.Get("JSON").ToObject(rt).Get("parse"))
	out, err := parse(goja.Undefined(), rt.ToValue(string(b)))
	if err != nil {
		return goja.Null()
	}
	return out
}

func normalize(v any) any {
	switch x := v.(type) {
	case float64:
		if x == math.Trunc(x) && math.Abs(x) < 1<<53 {
			return int64(x)
		}
		return x
	case int:
		return int64(x)
	case []any:
		out := make([]any, len(x))
		for i, e := range x {
			out[i] = normalize(e)
		}
		return out
	case map[string]any:
		out := make(map[string]any, len(x))
		for k, e := range x {
			out[k] = normalize(e)
		}
		return out
	}
	return v
}

func nullOr(rt *goja.Runtime, v any) goja.Value {
	if v == nil {
		return goja.Null()
	}
	return toJS(rt, v)
}

func newContext(goctx context.Context, s *state, call Call) goja.Value {
	rt := s.rt
	req := call.Request
	request := rt.NewObject()
	_ = request.Set("method", req.Method)
	_ = request.Set("path", req.Path)
	_ = request.Set("query", func(name string) goja.Value {
		vals, ok := req.Query[name]
		if !ok || len(vals) == 0 {
			return goja.Null()
		}
		return rt.ToValue(vals[0])
	})
	_ = request.Set("header", func(name string) goja.Value {
		if vals := req.Header.Values(name); len(vals) > 0 {
			return rt.ToValue(vals[0])
		}
		return goja.Null()
	})

	_ = request.Set("body", req.Body)
	_ = request.Set("json", func() goja.Value {
		v, err := requestJSON(req.Body)
		if err != nil {
			s.throw(err)
		}
		return toJS(rt, v)
	})
	_ = request.Set("accept", req.Header.Get("Accept"))
	_ = request.Set("accepts", func(typ string) bool { return accepts(req.Header.Get("Accept"), typ) })

	c := rt.NewObject()
	_ = c.Set("request", request)
	_ = c.Set("crypto", gojaCrypto(s))
	_ = c.Set("time", gojaTime(s))
	_ = c.Set("response", func(v goja.Value) goja.Value {
		if !call.RawAllowed {
			s.throw(notAllowedResponse())
		}
		fields := map[string]any{}
		if o, ok := v.(*goja.Object); !ok || o.ClassName() != "Object" {
			s.throw(responseErr("expects an object {status, headers, body}, got %s", describe(v)))
		} else if err := rt.ExportTo(o, &fields); err != nil {
			s.throw(responseErr("%v", err))
		}
		resp, err := buildResponse(fields)
		if err != nil {
			s.throw(err)
		}
		return rt.ToValue(resp)
	})
	_ = c.Set("operation", call.Operation)
	_ = c.Set("phase", call.Phase)
	_ = c.Set("validating", call.Validating)
	values := call.Values
	if values == nil {
		values = map[string]any{}
	}
	_ = c.Set("get", func(name string) goja.Value {
		return nullOr(rt, values[name])
	})
	_ = c.Set("set", func(name string, v goja.Value) {
		cv, err := exportValue(v)
		if err != nil {
			s.throwf("ctx.set(%q): %s", name, err)
		}
		values[name] = cv
	})
	_ = c.Set("property", func(name string) goja.Value {
		if call.Property == nil {
			s.throw(&apierr.Error{Code: apierr.InvalidHelperCall, Message: "ctx.property is only available in parameter handlers", Hint: "In a query, reference your own SQL aliases; ctx.property names the columns of the query result for a handler's condition."})
		}
		f, err := call.Property(name)
		if err != nil {
			s.throw(asAPIErr(err))
		}
		return rt.ToValue(f)
	})
	_ = c.Set("resource", func(name string) goja.Value {
		if call.Resources == nil {
			s.throwf("ctx.resource is not available here")
		}
		h, err := call.Resources.Resource(name)
		if err != nil {
			s.throw(asAPIErr(err))
		}
		return helperObject(s, h)
	})
	if call.Operation == "collection" {
		_ = c.Set("page", call.Page)
		_ = c.Set("itemsPerPage", call.ItemsPerPage)
		filters := call.Filters
		if filters == nil {
			filters = map[string]any{}
		}
		_ = c.Set("filters", toJS(rt, filters))
	}
	_ = c.Set("id", nullOr(rt, call.ID))
	_ = c.Set("sql", func(fc goja.FunctionCall) goja.Value {
		return rt.ToValue(tagged(rt, fc))
	})

	host := call.Host
	needHost := func(what string) {
		if host == nil {
			s.throwf("%s is not available here", what)
		}
	}
	fragmentArg := func(what string, v goja.Value) sqlf.Fragment {
		if f, ok := v.Export().(sqlf.Fragment); ok {
			return f
		}
		s.throwf("%s expects a ctx.sql`...` fragment, not %s; build the statement with ctx.sql so values are bound as parameters", what, describe(v))
		return sqlf.Fragment{}
	}
	db := rt.NewObject()
	_ = db.Set("query", func(v goja.Value) goja.Value {
		needHost("ctx.db")
		rows, err := host.Query(goctx, fragmentArg("ctx.db.query", v))
		if err != nil {
			s.throw(asAPIErr(err))
		}
		if rows == nil {
			rows = []map[string]any{}
		}
		return toJS(rt, rows)
	})
	_ = db.Set("exec", func(v goja.Value) goja.Value {
		needHost("ctx.db")
		n, err := host.Exec(goctx, fragmentArg("ctx.db.exec", v))
		if err != nil {
			s.throw(asAPIErr(err))
		}
		res := rt.NewObject()
		_ = res.Set("rowCount", n)
		return res
	})
	_ = c.Set("db", db)
	_ = c.Set("fetch", func(u string, opts goja.Value) goja.Value {
		needHost("ctx.fetch")
		r := FetchRequest{URL: u, Method: "GET", Headers: map[string]string{}}
		if opts != nil && !goja.IsUndefined(opts) && !goja.IsNull(opts) {
			o := opts.ToObject(rt)
			if m := o.Get("method"); m != nil && !goja.IsUndefined(m) {
				r.Method = strings.ToUpper(m.String())
			}
			if h := o.Get("headers"); h != nil && !goja.IsUndefined(h) && !goja.IsNull(h) {
				ho := h.ToObject(rt)
				for _, k := range ho.Keys() {
					r.Headers[strings.ToLower(k)] = ho.Get(k).String()
				}
			}
			if b := o.Get("body"); b != nil && !goja.IsUndefined(b) && !goja.IsNull(b) {
				if _, isObj := b.(*goja.Object); isObj {
					stringify, _ := goja.AssertFunction(rt.Get("JSON").ToObject(rt).Get("stringify"))
					sv, _ := stringify(goja.Undefined(), b)
					r.Body = sv.String()
					if _, ok := r.Headers["content-type"]; !ok {
						r.Headers["content-type"] = "application/json"
					}
				} else {
					r.Body = b.String()
				}
			}
		}
		resp, err := host.Fetch(goctx, r)
		if err != nil {
			s.throw(asAPIErr(err))
		}
		out := rt.NewObject()
		_ = out.Set("status", resp.Status)
		_ = out.Set("ok", resp.Status >= 200 && resp.Status < 300)
		_ = out.Set("headers", toJS(rt, resp.Headers))
		body := string(resp.Body)
		_ = out.Set("text", func() string { return body })
		_ = out.Set("json", func() goja.Value {
			parse, _ := goja.AssertFunction(rt.Get("JSON").ToObject(rt).Get("parse"))
			v, err := parse(goja.Undefined(), rt.ToValue(body))
			if err != nil {
				s.throw(&apierr.Error{Code: apierr.FetchFailed, Message: fmt.Sprintf("response of %s %s is not JSON: %s", r.Method, r.URL, truncate(body, 120)), Hint: "Use res.text() for non-JSON responses, and check res.status first."})
			}
			return v
		})
		return out
	})
	_ = c.Set("env", func(name string) goja.Value {
		needHost("ctx.env")
		v, err := host.Env(name)
		if err != nil {
			s.throw(asAPIErr(err))
		}
		return rt.ToValue(v)
	})
	_ = c.Set("reject", func(v goja.Value) {
		e := &apierr.Error{Code: apierr.Rejected, Rejected: true}
		if o, ok := v.(*goja.Object); ok && o.ClassName() == "Object" {
			str := func(k string) string {
				if x := o.Get(k); x != nil && !goja.IsUndefined(x) && !goja.IsNull(x) {
					return x.String()
				}
				return ""
			}
			if c := str("code"); c != "" {
				e.Code = apierr.Code(c)
			}
			e.Message, e.Pointer, e.Hint = str("message"), str("pointer"), str("hint")
			if st := o.Get("status"); st != nil && !goja.IsUndefined(st) {
				if n := int(st.ToInteger()); n >= 400 && n < 600 {
					e.HTTPStatus = n
				}
			}
		} else if v != nil && !goja.IsUndefined(v) {
			e.Message = v.String()
		}
		if e.Message == "" {
			e.Message = "the input was rejected"
		}
		s.throw(e)
	})
	return c
}

func exportValue(v goja.Value) (any, error) {
	if v == nil || goja.IsUndefined(v) || goja.IsNull(v) {
		return nil, nil
	}
	if f, ok := v.Export().(sqlf.Fragment); ok {
		return f, nil
	}
	b, err := json.Marshal(v.Export())
	if err != nil {
		return nil, fmt.Errorf("the value cannot be stored: %v", err)
	}
	var out any
	if err := json.Unmarshal(b, &out); err != nil {
		return nil, err
	}
	return normalize(out), nil
}

// helperObject exposes a SQLHelper to JavaScript; fragments it returns can
// be interpolated in ctx.sql.
func helperObject(s *state, h SQLHelper) goja.Value {
	rt := s.rt
	o := rt.NewObject()
	frag := func(f sqlf.Fragment, err error) goja.Value {
		if err != nil {
			s.throw(asAPIErr(err))
		}
		return rt.ToValue(f)
	}
	names := func(v goja.Value) []string {
		if v == nil || goja.IsUndefined(v) || goja.IsNull(v) {
			return nil
		}
		var out []string
		if err := rt.ExportTo(v, &out); err != nil {
			s.throwf("expected an array of property names, got %s", describe(v))
		}
		if out == nil {
			out = []string{}
		}
		return out
	}
	_ = o.Set("table", func() goja.Value { return rt.ToValue(h.Table()) })
	_ = o.Set("alias", func() string { return h.Alias() })
	_ = o.Set("column", func(name string) goja.Value { return frag(h.Column(name)) })
	_ = o.Set("columns", func(v goja.Value) goja.Value { return frag(h.Columns(names(v))) })
	_ = o.Set("identifier", func(v goja.Value) goja.Value {
		cv, err := convert(v)
		if err != nil {
			s.throwf("identifier(): %s", err)
		}
		return rt.ToValue(h.Identifier(cv))
	})
	_ = o.Set("join", func(name string) goja.Value { return frag(h.Join(name)) })
	_ = o.Set("json", func(v goja.Value) goja.Value { return frag(h.JSON(names(v))) })
	_ = o.Set("relation", func(name string) goja.Value {
		sub, err := h.Relation(name)
		if err != nil {
			s.throw(asAPIErr(err))
		}
		return helperObject(s, sub)
	})
	return o
}

func asAPIErr(err error) *apierr.Error {
	if e, ok := apierr.As(err); ok {
		return e
	}
	return &apierr.Error{Code: apierr.InternalError, Message: err.Error()}
}

func describe(v goja.Value) string {
	if v == nil || goja.IsUndefined(v) {
		return "undefined"
	}
	if goja.IsNull(v) {
		return "null"
	}
	return fmt.Sprintf("a %s (%s)", v.ExportType(), truncate(v.String(), 60))
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "..."
}

func tagged(rt *goja.Runtime, call goja.FunctionCall) sqlf.Fragment {
	strsVal := call.Argument(0)
	if goja.IsUndefined(strsVal) || goja.IsNull(strsVal) {
		panic(rt.NewTypeError("ctx.sql must be used as a tagged template: ctx.sql`SELECT ...`"))
	}
	strsObj := strsVal.ToObject(rt)
	if strsObj.ClassName() != "Array" {
		panic(rt.NewTypeError("ctx.sql must be used as a tagged template: ctx.sql`SELECT ...`, not called with a string"))
	}
	n := int(strsObj.Get("length").ToInteger())
	strs := make([]string, n)
	for i := range n {
		strs[i] = strsObj.Get(fmt.Sprint(i)).String()
	}
	values := make([]any, 0, len(call.Arguments))
	for i, a := range call.Arguments[1:] {
		v, err := convert(a)
		if err != nil {
			panic(rt.NewTypeError(fmt.Sprintf("ctx.sql interpolation #%d: %s", i+1, err)))
		}
		values = append(values, v)
	}
	return sqlf.Tagged(strs, values)
}

func convert(v goja.Value) (any, error) {
	if v == nil || goja.IsUndefined(v) || goja.IsNull(v) {
		return nil, nil
	}
	switch x := v.Export().(type) {
	case sqlf.Fragment, string, bool, int64:
		return x, nil
	case float64:
		if x == math.Trunc(x) && math.Abs(x) < 1<<53 {
			return int64(x), nil
		}
		return x, nil
	case []any:
		return convertArray(x)
	case time.Time:
		return x, nil
	default:
		return nil, fmt.Errorf("cannot interpolate %T; interpolate strings, numbers, booleans, null, arrays of those, or nested ctx.sql fragments", x)
	}
}

func convertArray(items []any) (any, error) {
	var strs []string
	var ints []int64
	var floats []float64
	var bools []bool
	for _, it := range items {
		switch x := it.(type) {
		case string:
			strs = append(strs, x)
		case int64:
			ints = append(ints, x)
			floats = append(floats, float64(x))
		case float64:
			floats = append(floats, x)
		case bool:
			bools = append(bools, x)
		default:
			return nil, fmt.Errorf("cannot interpolate an array containing %T", it)
		}
	}
	switch len(items) {
	case len(strs):
		if strs == nil {
			return []string{}, nil
		}
		return strs, nil
	case len(ints):
		return ints, nil
	case len(floats):
		return floats, nil
	case len(bools):
		return bools, nil
	}
	return nil, fmt.Errorf("cannot interpolate an array mixing types")
}

// strArg reads a string argument of a stdlib helper: numbers and undefined
// are refused rather than silently stringified.
func strArg(s *state, what string, v goja.Value) string {
	if x, ok := v.Export().(string); ok {
		return x
	}
	s.throw(&apierr.Error{Code: apierr.InvalidCryptoCall, Message: fmt.Sprintf("%s expects a string, got %s", what, describe(v)), Hint: "Convert with String(x) first; scripts handle text (UTF-8) only."})
	return ""
}

func gojaCrypto(s *state) goja.Value {
	rt := s.rt
	o := rt.NewObject()
	unwrap := func(v string, err *apierr.Error) goja.Value {
		if err != nil {
			s.throw(err)
		}
		return rt.ToValue(v)
	}
	for name := range hashAlgos {
		_ = o.Set(name, func(v goja.Value) string { return hashHex(name, strArg(s, "ctx.crypto."+name, v)) })
	}
	_ = o.Set("hmac", func(algo, key, data goja.Value) goja.Value {
		return unwrap(hmacHex(strArg(s, "ctx.crypto.hmac", algo), strArg(s, "ctx.crypto.hmac", key), strArg(s, "ctx.crypto.hmac", data)))
	})
	_ = o.Set("equal", func(a, b goja.Value) bool {
		return constantTimeEqual(strArg(s, "ctx.crypto.equal", a), strArg(s, "ctx.crypto.equal", b))
	})
	_ = o.Set("randomHex", func(v goja.Value) goja.Value {
		n, ok := integer(v.Export())
		if !ok {
			s.throw(cryptoErr("ctx.crypto.randomHex expects a number of bytes, got %s", describe(v)))
		}
		return unwrap(randomHex(n))
	})
	_ = o.Set("uuid", func() string { return newUUID() })
	_ = o.Set("base64Encode", func(v goja.Value) string { return base64Encode(strArg(s, "ctx.crypto.base64Encode", v)) })
	_ = o.Set("base64Decode", func(v goja.Value) goja.Value { return unwrap(base64Decode(strArg(s, "ctx.crypto.base64Decode", v))) })
	_ = o.Set("hexEncode", func(v goja.Value) string { return hexEncode(strArg(s, "ctx.crypto.hexEncode", v)) })
	_ = o.Set("hexDecode", func(v goja.Value) goja.Value { return unwrap(hexDecode(strArg(s, "ctx.crypto.hexDecode", v))) })
	return o
}

func gojaTime(s *state) goja.Value {
	rt := s.rt
	o := rt.NewObject()
	_ = o.Set("now", func() string { return timeNow() })
	_ = o.Set("unix", func() float64 { return timeUnix() })
	_ = o.Set("parse", func(v goja.Value) goja.Value {
		str, ok := v.Export().(string)
		if !ok {
			s.throw(&apierr.Error{Code: apierr.InvalidTimeValue, Message: "ctx.time.parse expects an ISO 8601 string, got " + describe(v), Hint: `Pass "2024-01-31T12:00:00Z"; numbers are already epoch seconds.`})
		}
		f, err := timeParse(str)
		if err != nil {
			s.throw(err)
		}
		return rt.ToValue(f)
	})
	_ = o.Set("format", func(v goja.Value) goja.Value {
		f, ok := v.Export().(float64)
		if i, isInt := v.Export().(int64); isInt {
			f, ok = float64(i), true
		}
		if !ok {
			s.throw(&apierr.Error{Code: apierr.InvalidTimeValue, Message: "ctx.time.format expects a number of epoch seconds, got " + describe(v), Hint: "Use ctx.time.unix() or ctx.time.parse(iso) to get one."})
		}
		out, err := timeFormat(f)
		if err != nil {
			s.throw(err)
		}
		return rt.ToValue(out)
	})
	return o
}
