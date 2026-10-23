package httpapi

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime"
	"net/http"
	"net/url"
	"slices"
	"sort"
	"strings"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
)

// Output is the site-level output configuration (Caddyfile), overridden by
// the same keys of a definition.
type Output struct {
	Formats  []string
	Envelope map[string]string
	Errors   resource.ErrorShape
	// ErrorDetails false drops code, hint, pointer and errors from shaped
	// errors; nil or true keeps them as extra keys.
	ErrorDetails *bool
}

type Server struct {
	Set     *resource.Set
	Runtime *resource.Runtime
	// MCP is served at /mcp when non-nil.
	MCP     http.Handler
	OnError func(r *http.Request, err *apierr.Error)
	// Global are definition errors tied to no definition (missing
	// directory, database down at load).
	Global apierr.List
	Output Output
}

const (
	FormatJSONLD = "application/ld+json"
	FormatJSON   = "application/json"
	FormatCSV    = "text/csv"
)

const maxBody = 10 << 20

type rawBodyKey struct{}

// RequestFrom builds the script view of a request. The body is the one
// captured by ServeHTTP, so scripts read it whatever the operation parses.
func RequestFrom(r *http.Request) script.Request {
	body, _ := r.Context().Value(rawBodyKey{}).(string)
	return script.Request{Method: r.Method, Path: r.URL.Path, Query: r.URL.Query(), RawQuery: r.URL.RawQuery, Header: r.Header, Body: body}
}

func captureBody(r *http.Request) *http.Request {
	if r.Body == nil || r.Body == http.NoBody {
		return r
	}
	b, _ := io.ReadAll(io.LimitReader(r.Body, maxBody))
	r.Body.Close()
	r.Body = io.NopCloser(bytes.NewReader(b))
	return r.WithContext(context.WithValue(r.Context(), rawBodyKey{}, string(b)))
}

// route is a request matched to a definition: the operation-specific view
// of the definition, and the raw identifier for item requests.
type route struct {
	def   *resource.Definition
	view  *resource.Definition
	op    *resource.Operation
	item  bool
	rawID string
}

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	if path == "/mcp" && s.MCP != nil {
		s.MCP.ServeHTTP(w, r)
		return
	}
	r = captureBody(r)
	read := r.Method == http.MethodGet || r.Method == http.MethodHead
	base := baseURL(r)
	switch {
	case path == "/" && read:
		s.write(w, http.StatusOK, FormatJSONLD, entrypointDoc(s.Set))
		return
	case path == "/openapi.json" && read:
		s.write(w, http.StatusOK, FormatJSON, openAPIDoc(s.Set, s.Output))
		return
	case path == "/contexts/Entrypoint" && read:
		s.write(w, http.StatusOK, FormatJSONLD, entrypointContextDoc(s.Set, base))
		return
	case strings.HasPrefix(path, "/contexts/") && read:
		if d := s.Set.ByName(strings.TrimPrefix(path, "/contexts/")); d != nil {
			s.write(w, http.StatusOK, FormatJSONLD, contextDoc(d, base))
			return
		}
	}

	trimmed, suffix := splitSuffix(path)
	var methods []string
	var allowDef *resource.Definition
	for _, d := range s.Set.Defs {
		for i := range d.Ops {
			op := &d.Ops[i]
			rawID, ok := matchOperation(*op, path, trimmed)
			if !ok {
				continue
			}
			if op.Method == r.Method || (r.Method == http.MethodHead && op.Method == http.MethodGet) {
				opSuffix := suffix
				if path == op.Path || (op.IsItem() && !strings.HasPrefix(path, trimmed)) {
					opSuffix = ""
				}
				s.operation(w, r, route{def: d, view: d.ForOperation(*op), op: op, item: op.IsItem(), rawID: rawID}, opSuffix)
				return
			}
			methods = append(methods, op.Method)
			allowDef = d
		}
	}
	if len(methods) > 0 {
		s.methodNotAllowed(w, r, allowDef, methods...)
		return
	}

	for _, d := range s.Set.Defs {
		if len(d.Ops) > 0 || d.Path == "" {
			continue
		}
		if trimmed == d.Path || trimmed == d.Path+"/" {
			s.collection(w, r, d, suffix)
			return
		}
		if rest, ok := strings.CutPrefix(trimmed, d.Path+"/"); ok && rest != "" && !strings.Contains(rest, "/") {
			id, _ := url.PathUnescape(rest)
			s.item(w, r, route{def: d, view: d, item: true, rawID: id}, suffix)
			return
		}
	}
	for _, b := range s.Set.Broken {
		for _, p := range b.Paths {
			prefix := p
			if i := strings.Index(p, "/{"); i >= 0 {
				prefix = p[:i]
			}
			if trimmed == prefix || strings.HasPrefix(trimmed, prefix+"/") || path == p {
				first := *b.Errors[0]
				first.Message = fmt.Sprintf("%s is not served because %s has %d error(s); first: %s", p, b.File, len(b.Errors), first.Message)
				s.problem(w, r, &first, b.Errors...)
				return
			}
		}
	}
	hint := "Available paths: " + strings.Join(s.paths(), ", ") + "."
	for _, e := range s.Global {
		hint += fmt.Sprintf(" Definitions error %s: %s.", e.Code, e.Message)
	}
	if len(s.Set.Broken) > 0 {
		var files []string
		for _, b := range s.Set.Broken {
			files = append(files, fmt.Sprintf("%s (%s)", b.File, b.Errors[0].Code))
		}
		hint += " Definitions with errors, not served: " + strings.Join(files, ", ") + ". Run `caddy resource validate` for details."
	}
	s.fail(w, r, nil, &apierr.Error{Code: apierr.NotFound, Message: fmt.Sprintf("no route matches %s %s", r.Method, path), Hint: hint})
}

// matchOperation matches the path of an operation; an item operation
// (/authors/{id}, /purchases/{id}/refund) captures the identifier segment,
// format suffix removed.
func matchOperation(op resource.Operation, path, trimmed string) (string, bool) {
	if !op.IsItem() {
		return "", op.Path == path || op.Path == trimmed
	}
	candidates := []string{trimmed}
	if op.Suffix() != "" {
		candidates = []string{path, trimmed}
	}
	for _, p := range candidates {
		rest, ok := strings.CutPrefix(p, op.Prefix()+"/")
		if ok {
			rest, ok = strings.CutSuffix(rest, op.Suffix())
		}
		if !ok || rest == "" || strings.Contains(rest, "/") {
			continue
		}
		if id, err := url.PathUnescape(rest); err == nil {
			return id, true
		}
	}
	return "", false
}

func (s *Server) methodNotAllowed(w http.ResponseWriter, r *http.Request, d *resource.Definition, allow ...string) {
	w.Header().Set("Allow", strings.Join(allow, ", "))
	s.fail(w, r, d, &apierr.Error{Code: apierr.MethodNotAllowed, Message: fmt.Sprintf("method %s is not allowed on %s", r.Method, r.URL.Path), Hint: "Allowed here: " + strings.Join(allow, ", ") + "."})
}

func (s *Server) collection(w http.ResponseWriter, r *http.Request, d *resource.Definition, suffix string) {
	switch r.Method {
	case http.MethodGet, http.MethodHead:
		f, err := s.chooseFormat(r, d, suffix)
		if err != nil {
			s.fail(w, r, d, err)
			return
		}
		s.serveCollection(w, r, d, f, nil)
	case http.MethodPost:
		s.create(w, r, d, suffix)
	default:
		s.methodNotAllowed(w, r, d, "GET", "HEAD", "POST")
	}
}

// committer delays the status line until the first byte of the body, so a
// streamed response can still turn into an error before anything is sent.
type committer struct {
	w         http.ResponseWriter
	before    func()
	committed bool
}

func (c *committer) Write(p []byte) (int, error) {
	if !c.committed {
		c.committed = true
		c.before()
	}
	return c.w.Write(p)
}

func (c *committer) Flush() {
	if f, ok := c.w.(http.Flusher); ok && c.committed {
		f.Flush()
	}
}

// serveCollection renders a page, or every row when the format is CSV and no
// ?page is given (an export wants all rows, streamed).
//
// unacceptable is the 406 of a negotiation that failed but may still be
// answered by the query itself with ctx.response (see scriptMayAnswer): it is
// returned unless the script sends a raw response.
func (s *Server) serveCollection(w http.ResponseWriter, r *http.Request, d *resource.Definition, f Format, unacceptable error) {
	ctx, req := r.Context(), RequestFrom(r)
	u := &url.URL{Path: r.URL.Path, RawQuery: r.URL.RawQuery}
	u.Path, _ = splitSuffix(u.Path)
	ex, err := s.Runtime.Prepare(ctx, d, req, true)
	if err != nil {
		s.fail(w, r, d, err)
		return
	}
	_, paged := r.URL.Query()["page"]
	if f.Name() == "csv" && !paged && unacceptable == nil {
		c := &committer{w: w, before: func() {
			s.headers(w, r, f)
			w.WriteHeader(http.StatusOK)
		}}
		err := f.WriteCollection(c, Collection{Def: d, URL: u, Each: func(yield func(resource.Item) error) error {
			return s.Runtime.Stream(ctx, d, req, ex, yield)
		}})
		switch {
		case err != nil && !c.committed:
			s.fail(w, r, d, err)
		case err != nil:
			s.logError(r, err)
		case !c.committed:
			c.Write(nil)
		}
		return
	}
	items, total, err := s.Runtime.List(ctx, d, req, ex)
	if err == nil {
		err = unacceptable
	}
	if err != nil {
		s.fail(w, r, d, err)
		return
	}
	s.render(w, r, d, http.StatusOK, f, func(wr io.Writer) error {
		return f.WriteCollection(wr, Collection{Def: d, URL: u, Total: total, Page: ex.Page, ItemsPerPage: ex.ItemsPerPage, Paginated: true, Envelope: s.envelope(d), Each: func(yield func(resource.Item) error) error {
			for _, it := range items {
				if err := yield(it); err != nil {
					return err
				}
			}
			return nil
		}})
	})
}

func (s *Server) logError(r *http.Request, err error) {
	if s.OnError == nil {
		return
	}
	e, ok := apierr.As(err)
	if !ok {
		e = &apierr.Error{Code: apierr.InternalError, Message: err.Error()}
	}
	s.OnError(r, e)
}

func (s *Server) headers(w http.ResponseWriter, r *http.Request, f Format) {
	w.Header().Set("Content-Type", f.MediaType()+"; charset=utf-8")
	if f.Name() == "csv" {
		p, _ := splitSuffix(r.URL.Path)
		name := strings.ReplaceAll(strings.Trim(p, "/"), "/", "-")
		if name == "" {
			name = "export"
		}
		w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", name+".csv"))
	}
}

// render buffers the document so a serialization error can still become an
// error response.
func (s *Server) render(w http.ResponseWriter, r *http.Request, d *resource.Definition, status int, f Format, write func(io.Writer) error) {
	var b bytes.Buffer
	if err := write(&b); err != nil {
		s.fail(w, r, d, err)
		return
	}
	s.headers(w, r, f)
	w.WriteHeader(status)
	_, _ = w.Write(b.Bytes())
}

func (s *Server) renderItem(w http.ResponseWriter, r *http.Request, status int, d *resource.Definition, f Format, item resource.Item) {
	s.render(w, r, d, status, f, func(wr io.Writer) error { return f.WriteItem(wr, d, item) })
}

// scriptMayAnswer tells a failed content negotiation that the query of a
// dedicated GET operation, or the command of an item write operation, may
// still answer, with ctx.response, for the requested type (an HTML page next
// to the JSON representation).
func (s *Server) scriptMayAnswer(d *resource.Definition, err *apierr.Error) bool {
	if err.Code != apierr.NotAcceptable || d.Current == nil {
		return false
	}
	if d.Current.Reads() {
		return d.QueryMod != nil
	}
	return d.Current.IsItem() && d.CommandMod != nil
}

func (s *Server) item(w http.ResponseWriter, r *http.Request, rt route, suffix string) {
	d := rt.view
	var mode, op string
	method := r.Method
	if rt.op != nil && rt.op.Reads() {
		method = http.MethodGet
	}
	switch method {
	case http.MethodGet, http.MethodHead:
	case http.MethodPost:
		if rt.op == nil {
			s.methodNotAllowed(w, r, d, "GET", "HEAD", "PUT", "PATCH", "DELETE")
			return
		}
		mode, op = resource.ModeCreate, "create"
	case http.MethodPut:
		mode, op = resource.ModeReplace, "update"
	case http.MethodPatch:
		mode, op = resource.ModePatch, "update"
	case http.MethodDelete:
		op = "delete"
	default:
		s.methodNotAllowed(w, r, d, "GET", "HEAD", "PUT", "PATCH", "DELETE")
		return
	}
	f, ferr := s.chooseFormat(r, d, suffix)
	var unacceptable error
	if ferr != nil && op != "delete" {
		if !s.scriptMayAnswer(d, ferr) {
			s.fail(w, r, d, ferr)
			return
		}
		f, unacceptable = formatByName(s.formats(d)[0]), ferr
	}
	ctx, req := r.Context(), RequestFrom(r)
	if op == "" {
		ex, err := s.Runtime.Prepare(ctx, d, req, false)
		if err != nil {
			s.fail(w, r, d, err)
			return
		}
		item, _, err := s.find(ctx, d, req, rt.rawID, "read", ex)
		if err == nil {
			err = unacceptable
		}
		if err != nil {
			s.fail(w, r, d, err)
			return
		}
		s.renderItem(w, r, http.StatusOK, d, f, item)
		return
	}
	if rt.op == nil {
		if err := checkOperation(d, op); err != nil {
			s.fail(w, r, d, err)
			return
		}
	}
	item, status, err := s.update(ctx, d, op, mode, req, rt.rawID, func() (map[string]any, error) {
		if op == "delete" || d.IgnoresBody() {
			return nil, nil
		}
		return readJSON(r)
	})
	if err != nil {
		s.fail(w, r, d, err)
		return
	}
	if item == nil {
		w.WriteHeader(status)
		return
	}
	if unacceptable != nil {
		s.fail(w, r, d, unacceptable)
		return
	}
	s.renderItem(w, r, status, d, f, item)
}

func checkOperation(d *resource.Definition, op string) error {
	if slices.Contains(d.Operations(), op) {
		return nil
	}
	switch {
	case d.ReadOnly:
		return &apierr.Error{Code: apierr.ReadOnlyResource, Resource: d.Name, Pointer: "/readOnly", Message: d.Name + " is read-only", Hint: "Remove \"readOnly\": true from the definition to accept writes."}
	case d.CommandMod == nil:
		return &apierr.Error{Code: apierr.ReadOnlyResource, Resource: d.Name, Pointer: "/query", Message: d.Name + " uses a query and has no command, so it cannot be written", Hint: "Add \"command\" (a <name>.command.ts or <name>.star file) defining create/update/remove functions, or \"table\" to get generated INSERT/UPDATE/DELETE; a query only describes how to read."}
	}
	fn := op
	if op == "delete" {
		fn = "remove"
	}
	return &apierr.Error{Code: apierr.OperationNotSupported, Resource: d.Name, Pointer: "/command", Message: fmt.Sprintf("%s does not define %s", d.Command, fn), Hint: fmt.Sprintf("Add a %s(input, ctx) function to %s.", fn, d.Command)}
}

// readJSON reads a JSON object body; CSV and other types are refused.
func readJSON(r *http.Request) (map[string]any, error) {
	if raw := r.Header.Get("Content-Type"); raw != "" {
		mt, _, err := mime.ParseMediaType(raw)
		switch {
		case err == nil && (mt == FormatJSON || mt == FormatJSONLD || mt == "application/merge-patch+json"):
		default:
			return nil, &apierr.Error{Code: apierr.UnsupportedMediaType, Message: fmt.Sprintf("cannot read a %q body", raw), Hint: "Request bodies are JSON: send Content-Type application/json (or application/ld+json, application/merge-patch+json) with one JSON object. CSV is an output format only."}
		}
	}
	b, err := io.ReadAll(io.LimitReader(r.Body, maxBody))
	if err != nil {
		return nil, &apierr.Error{Code: apierr.InvalidBody, Message: err.Error()}
	}
	if len(bytes.TrimSpace(b)) == 0 {
		return map[string]any{}, nil
	}
	var v any
	if err := json.Unmarshal(b, &v); err != nil {
		return nil, &apierr.Error{Code: apierr.InvalidBody, Message: "the body is not valid JSON: " + err.Error(), Hint: "Send one JSON object, e.g. {\"title\": \"Dune\"}."}
	}
	m, ok := v.(map[string]any)
	if !ok {
		return nil, &apierr.Error{Code: apierr.InvalidBody, Message: "the body must be a JSON object", Hint: "Send one JSON object per request."}
	}
	return m, nil
}

func (s *Server) create(w http.ResponseWriter, r *http.Request, d *resource.Definition, suffix string) {
	if err := checkOperation(d, "create"); err != nil {
		s.fail(w, r, d, err)
		return
	}
	f, ferr := s.chooseFormat(r, d, suffix)
	if ferr != nil {
		s.fail(w, r, d, ferr)
		return
	}
	ctx, req := r.Context(), RequestFrom(r)
	ex, err := s.Runtime.Prepare(ctx, d, req, false)
	if err != nil {
		s.fail(w, r, d, err)
		return
	}
	body, err := readJSON(r)
	if err != nil {
		s.fail(w, r, d, err)
		return
	}
	id, item, err := s.createWith(ctx, d, req, body, ex)
	if err != nil {
		s.fail(w, r, d, err)
		return
	}
	if loc := itemPath(d, id); loc != "" {
		w.Header().Set("Location", loc)
	}
	if item == nil {
		w.WriteHeader(http.StatusCreated)
		return
	}
	s.renderItem(w, r, http.StatusCreated, d, f, item)
}

// Create validates the body, runs the create command in a transaction and
// reads the row back through the query. item is nil when the query does not
// return the new row (its WHERE excludes it).
func (s *Server) Create(ctx context.Context, d *resource.Definition, req script.Request, body map[string]any) (any, resource.Item, error) {
	ex, err := s.Runtime.Prepare(ctx, d, req, false)
	if err != nil {
		return nil, nil, err
	}
	return s.createWith(ctx, d, req, body, ex)
}

func (s *Server) createWith(ctx context.Context, d *resource.Definition, req script.Request, body map[string]any, ex *resource.Exec) (any, resource.Item, error) {
	input, errs := resource.ValidateInput(d, resource.ModeCreate, body, nil)
	if len(errs) > 0 {
		return nil, nil, errs
	}
	id, err := s.Runtime.Write(ctx, d, "create", req, nil, input, ex)
	if err != nil {
		return nil, nil, err
	}
	id, err = normalizeID(d, id)
	if err != nil {
		return nil, nil, err
	}
	item, ok, err := s.Runtime.Get(ctx, d, req, id, "read", ex)
	if err != nil || !ok {
		return id, nil, err
	}
	return id, item, nil
}

func normalizeID(d *resource.Definition, id any) (any, error) {
	if id == nil {
		return nil, &apierr.Error{Code: apierr.InternalError, Resource: d.Name, Message: fmt.Sprintf("%s has no %s identifier value to act on", d.Name, d.Identifier().Name), Hint: "The command did not return the identifier of the row it wrote."}
	}
	if v, err := resource.ParseValue(d.Identifier().Type, fmt.Sprint(id)); err == nil {
		return v, nil
	}
	return id, nil
}

// Update runs PUT/PATCH (op "update") or DELETE (op "delete") on an item
// visible through the query in lookup phase. It returns the read-back item,
// or nil with the status to send without a body.
func (s *Server) Update(ctx context.Context, d *resource.Definition, op, mode string, req script.Request, rawID string, body map[string]any) (resource.Item, int, error) {
	if err := checkOperation(d, op); err != nil {
		return nil, 0, err
	}
	return s.update(ctx, d, op, mode, req, rawID, func() (map[string]any, error) { return body, nil })
}

func (s *Server) update(ctx context.Context, d *resource.Definition, op, mode string, req script.Request, rawID string, body func() (map[string]any, error)) (resource.Item, int, error) {
	ex, err := s.Runtime.Prepare(ctx, d, req, false)
	if err != nil {
		return nil, 0, err
	}
	_, id, err := s.find(ctx, d, req, rawID, "lookup", ex)
	if err != nil {
		if e, ok := apierr.As(err); ok && e.Code == apierr.NotFound && d.QueryMod != nil {
			s.lookupHint(ctx, d, rawID, e)
		}
		return nil, 0, err
	}
	input := map[string]any{}
	if op != "delete" {
		b, err := body()
		if err != nil {
			return nil, 0, err
		}
		in, errs := resource.ValidateInput(d, mode, b, id)
		if len(errs) > 0 {
			return nil, 0, errs
		}
		input = in
	}
	if _, err := s.Runtime.Write(ctx, d, op, req, id, input, ex); err != nil {
		return nil, 0, err
	}
	if op == "delete" {
		return nil, http.StatusNoContent, nil
	}
	item, ok, err := s.Runtime.Get(ctx, d, req, id, "read", ex)
	if err != nil {
		return nil, 0, err
	}
	if !ok {
		return nil, http.StatusNoContent, nil
	}
	return item, http.StatusOK, nil
}

func (s *Server) operation(w http.ResponseWriter, r *http.Request, rt route, suffix string) {
	op, d := rt.op, rt.view
	if rt.item {
		s.item(w, r, rt, suffix)
		return
	}
	f, ferr := s.chooseFormat(r, d, suffix)
	var unacceptable error
	if ferr != nil {
		if !s.scriptMayAnswer(d, ferr) {
			s.fail(w, r, d, ferr)
			return
		}
		f, unacceptable = formatByName(s.formats(d)[0]), ferr
	}
	if op.Reads() {
		s.serveCollection(w, r, d, f, unacceptable)
		return
	}
	ctx, req := r.Context(), RequestFrom(r)
	ex, err := s.Runtime.Prepare(ctx, d, req, false)
	if err != nil {
		s.fail(w, r, d, err)
		return
	}
	var body map[string]any
	if op.Method != http.MethodDelete && !d.IgnoresBody() {
		if body, err = readJSON(r); err != nil {
			s.fail(w, r, d, err)
			return
		}
	}
	item, err := s.runOperation(ctx, d, *op, req, body, ex)
	if err != nil {
		s.fail(w, r, d, err)
		return
	}
	if item == nil {
		w.WriteHeader(http.StatusNoContent)
		return
	}
	status := http.StatusOK
	if op.Method == http.MethodPost {
		status = http.StatusCreated
	}
	s.renderItem(w, r, status, d, f, item)
}

func (s *Server) runOperation(ctx context.Context, od *resource.Definition, op resource.Operation, req script.Request, body map[string]any, ex *resource.Exec) (resource.Item, error) {
	mode := map[string]string{http.MethodPost: resource.ModeCreate, http.MethodPut: resource.ModeReplace, http.MethodPatch: resource.ModePatch}[op.Method]
	input := map[string]any{}
	if op.Method != http.MethodDelete {
		in, errs := resource.ValidateInput(od, mode, body, nil)
		if len(errs) > 0 {
			return nil, errs
		}
		input = in
	}
	return s.Runtime.Run(ctx, od, op, req, input, ex)
}

// RunOperation executes a write operation declared in "operations".
func (s *Server) RunOperation(ctx context.Context, d *resource.Definition, i int, req script.Request, body map[string]any) (resource.Item, error) {
	op := d.Ops[i]
	od := d.ForOperation(op)
	ex, err := s.Runtime.Prepare(ctx, od, req, false)
	if err != nil {
		return nil, err
	}
	return s.runOperation(ctx, od, op, req, body, ex)
}

func (s *Server) paths() []string {
	out := []string{"/", "/openapi.json"}
	if s.MCP != nil {
		out = append(out, "/mcp (POST, MCP JSON-RPC)")
	}
	for _, d := range s.Set.Defs {
		for _, op := range d.Ops {
			out = append(out, op.Method+" "+op.Path)
		}
		if len(d.Ops) == 0 {
			out = append(out, d.Path, d.Path+"/{"+d.Identifier().Name+"}", "/contexts/"+d.Name)
		}
	}
	return out
}

func baseURL(r *http.Request) string {
	scheme := "http"
	if r.TLS != nil {
		scheme = "https"
	}
	return scheme + "://" + r.Host
}

// fail answers err in the error shape of d (or of the site when d is nil).
func (s *Server) fail(w http.ResponseWriter, r *http.Request, d *resource.Definition, err error) {
	var raw *resource.RawResponse
	if errors.As(err, &raw) {
		writeRaw(w, raw.Response)
		return
	}
	var list apierr.List
	var e *apierr.Error
	if errors.As(err, &list) && len(list) > 0 {
		e = list[0]
	} else if ae, ok := apierr.As(err); ok {
		e = ae
	} else {
		e = &apierr.Error{Code: apierr.InternalError, Message: err.Error()}
	}
	if d != nil && e.Resource == "" {
		e.Resource = d.Name
	}
	shape := s.errorShape(d)
	if len(shape) == 0 {
		s.problem(w, r, e, list...)
		return
	}
	if e.Status() >= 500 && s.OnError != nil {
		s.OnError(r, e)
	}
	details := s.Output.ErrorDetails
	if d != nil && d.ErrorDetails != nil {
		details = d.ErrorDetails
	}
	s.write(w, e.Status(), FormatJSON, shapedError(shape, e, list, details == nil || *details))
}

// writeRaw sends the response of ctx.response untouched: no envelope, no
// format negotiation. Content-Type defaults to text/plain.
func writeRaw(w http.ResponseWriter, resp *script.Response) {
	h := w.Header()
	for k, v := range resp.Headers {
		h.Set(k, v)
	}
	if h.Get("Content-Type") == "" {
		h.Set("Content-Type", "text/plain; charset=utf-8")
	}
	w.WriteHeader(resp.Status)
	_, _ = io.WriteString(w, resp.Body)
}

func (s *Server) errorShape(d *resource.Definition) resource.ErrorShape {
	if d != nil && d.Errors != nil {
		return d.Errors
	}
	return s.Output.Errors
}

func (s *Server) envelope(d *resource.Definition) map[string]string {
	if d.Envelope != nil {
		return d.Envelope
	}
	return s.Output.Envelope
}

// shapedError renders the configured shape; with details, the teaching
// fields (code, hint, pointer, errors) follow as extra keys unless the shape
// already uses those names.
func shapedError(shape resource.ErrorShape, e *apierr.Error, all apierr.List, details bool) Obj {
	keys := make([]string, 0, len(shape))
	for k := range shape {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	message := e.Message
	if len(all) > 1 {
		msgs := make([]string, 0, len(all))
		for _, x := range all {
			m := x.Message
			if x.Pointer != "" {
				m = x.Pointer + ": " + m
			}
			msgs = append(msgs, m)
		}
		message = strings.Join(msgs, "; ")
	}
	o := Obj{}
	for _, k := range keys {
		var v any
		switch shape[k] {
		case "message":
			v = message
		case "code":
			v = e.Code
		case "status":
			v = e.Status()
		case "pointer":
			v = e.Pointer
		case "hint":
			v = e.HintText()
		case "resource":
			v = e.Resource
		}
		o = append(o, kv{k, v})
	}
	if !details {
		return o
	}
	extra := func(k string, v any) {
		if _, taken := shape[k]; !taken {
			o = append(o, kv{k, v})
		}
	}
	extra("code", e.Code)
	if hint := e.HintText(); hint != "" {
		extra("hint", hint)
	}
	if e.Pointer != "" {
		extra("pointer", e.Pointer)
	}
	if len(all) > 1 {
		extra("errors", all)
	}
	return o
}

func (s *Server) write(w http.ResponseWriter, status int, contentType string, doc any) {
	b, err := Marshal(doc)
	if err != nil {
		status, contentType = http.StatusInternalServerError, "application/problem+json"
		b, _ = Marshal(problemDoc(&apierr.Error{Code: apierr.InternalError, Message: err.Error()}))
	}
	w.Header().Set("Content-Type", contentType+"; charset=utf-8")
	w.WriteHeader(status)
	_, _ = w.Write(append(b, '\n'))
}

// problem answers e as problem+json; all, when it holds several errors, is
// listed too.
func (s *Server) problem(w http.ResponseWriter, r *http.Request, e *apierr.Error, all ...*apierr.Error) {
	if e.Status() >= 500 && s.OnError != nil {
		s.OnError(r, e)
	}
	doc := problemDoc(e)
	if len(all) > 1 {
		doc = append(doc, kv{"errors", apierr.List(all)})
	}
	s.write(w, e.Status(), "application/problem+json", doc)
}

func (s *Server) list(ctx context.Context, d *resource.Definition, req script.Request) ([]resource.Item, int64, *resource.Exec, error) {
	ex, err := s.Runtime.Prepare(ctx, d, req, true)
	if err != nil {
		return nil, 0, nil, err
	}
	items, total, err := s.Runtime.List(ctx, d, req, ex)
	if err != nil {
		return nil, 0, nil, err
	}
	return items, total, ex, nil
}

func (s *Server) ListDocument(ctx context.Context, d *resource.Definition, req script.Request, u *url.URL) (Obj, error) {
	req.Query, req.RawQuery = u.Query(), u.RawQuery
	items, total, ex, err := s.list(ctx, d, req)
	if err != nil {
		return nil, err
	}
	return collectionDoc(d, items, total, ex.Page, ex.ItemsPerPage, u), nil
}

func (s *Server) ItemDocument(ctx context.Context, d *resource.Definition, req script.Request, rawID string) (Obj, error) {
	ex, err := s.Runtime.Prepare(ctx, d, req, false)
	if err != nil {
		return nil, err
	}
	item, _, err := s.find(ctx, d, req, rawID, "read", ex)
	if err != nil {
		return nil, err
	}
	return itemDoc(d, item, true), nil
}

// ItemObj renders an item as a JSON-LD document, for MCP.
func ItemObj(d *resource.Definition, item resource.Item) Obj {
	return itemDoc(d, item, true)
}

func (s *Server) find(ctx context.Context, d *resource.Definition, req script.Request, rawID, phase string, ex *resource.Exec) (resource.Item, any, error) {
	id := d.Identifier()
	list := d.Path
	if list == "" {
		list = "the collection"
	} else {
		list = "GET " + list
	}
	notFound := &apierr.Error{Code: apierr.NotFound, Resource: d.Name, Message: fmt.Sprintf("%s %q not found", d.Name, rawID), Hint: fmt.Sprintf("List existing items with %s; their identifier is the item URL.", list)}
	v, err := resource.ParseValue(id.Type, rawID)
	if err != nil {
		notFound.Hint = fmt.Sprintf("The %s identifier %q is an %s. ", d.Name, id.Name, id.Type) + notFound.Hint
		return nil, nil, notFound
	}
	item, ok, err := s.Runtime.Get(ctx, d, req, v, phase, ex)
	if err != nil {
		return nil, nil, err
	}
	if !ok {
		return nil, nil, notFound
	}
	if hid, hidden := d.HiddenIdentifier(); hidden {
		item[hid.Name] = v
	}
	return item, v, nil
}

func typeHint(typ string) string {
	switch typ {
	case "integer":
		return "Send an integer, e.g. 42."
	case "number":
		return "Send a number, e.g. 4.5."
	case "boolean":
		return "Send true or false."
	case "datetime":
		return "Send an RFC 3339 datetime (2024-01-31T12:00:00Z) or a date (2024-01-31)."
	}
	return "Send a string."
}

// OperationOutput runs operation i of d and returns its body as the pinned
// (or default) format renders it, for MCP. Item operations take the
// identifier in id.
func (s *Server) OperationOutput(ctx context.Context, d *resource.Definition, i int, req script.Request, id string, body map[string]any) (string, error) {
	out, err := s.operationOutput(ctx, d, i, req, id, body)
	var raw *resource.RawResponse
	if errors.As(err, &raw) {
		return raw.Body, nil
	}
	return out, err
}

func (s *Server) operationOutput(ctx context.Context, d *resource.Definition, i int, req script.Request, id string, body map[string]any) (string, error) {
	op := d.Ops[i]
	od := d.ForOperation(op)
	f := formatByName(op.Format)
	if f == nil {
		f = formatByName(s.formats(od)[0])
	}
	var b bytes.Buffer
	switch {
	case op.IsItem() && op.Reads():
		ex, err := s.Runtime.Prepare(ctx, od, req, false)
		if err != nil {
			return "", err
		}
		item, _, err := s.find(ctx, od, req, id, "read", ex)
		if err != nil {
			return "", err
		}
		err = f.WriteItem(&b, od, item)
		return b.String(), err
	case op.IsItem():
		mode, kind := resource.ModePatch, "update"
		switch op.Method {
		case http.MethodPost:
			mode, kind = resource.ModeCreate, "create"
		case http.MethodPut:
			mode = resource.ModeReplace
		case http.MethodDelete:
			kind = "delete"
		}
		item, _, err := s.update(ctx, od, kind, mode, req, id, func() (map[string]any, error) { return body, nil })
		if err != nil || item == nil {
			return "", err
		}
		err = f.WriteItem(&b, od, item)
		return b.String(), err
	case !op.Reads():
		item, err := s.RunOperation(ctx, d, i, req, body)
		if err != nil || item == nil {
			return "", err
		}
		err = f.WriteItem(&b, od, item)
		return b.String(), err
	}
	u := &url.URL{Path: op.Path, RawQuery: req.Query.Encode()}
	req.RawQuery = u.RawQuery
	ex, err := s.Runtime.Prepare(ctx, od, req, true)
	if err != nil {
		return "", err
	}
	if _, paged := req.Query["page"]; f.Name() == "csv" && !paged {
		err = f.WriteCollection(&b, Collection{Def: od, URL: u, Each: func(yield func(resource.Item) error) error {
			return s.Runtime.Stream(ctx, od, req, ex, yield)
		}})
		return b.String(), err
	}
	items, total, err := s.Runtime.List(ctx, od, req, ex)
	if err != nil {
		return "", err
	}
	err = f.WriteCollection(&b, Collection{Def: od, URL: u, Total: total, Page: ex.Page, ItemsPerPage: ex.ItemsPerPage, Paginated: true, Envelope: s.envelope(od), Each: func(yield func(resource.Item) error) error {
		for _, it := range items {
			if err := yield(it); err != nil {
				return err
			}
		}
		return nil
	}})
	return b.String(), err
}

const lookupAdvice = "the query must return the row when ctx.phase === \"lookup\" (writes look the item up in that phase), e.g. WHERE b.published OR ${ctx.phase === \"lookup\"}"

// lookupHint explains a write's 404 when the query, not the data, hides the
// row: reads may hide rows that writes must still reach.
func (s *Server) lookupHint(ctx context.Context, d *resource.Definition, rawID string, e *apierr.Error) {
	if d.Table == "" {
		e.Hint += " If the row exists but the query hides it from reads, " + lookupAdvice + "."
		return
	}
	id, err := resource.ParseValue(d.Identifier().Type, rawID)
	if err != nil {
		return
	}
	if exists, err := s.Runtime.TableHas(ctx, d, id); err == nil && exists {
		e.Hint = fmt.Sprintf("Table %s has a row with %s %s, but the query does not return it for this write: %s.", d.Table, d.Identifier().Name, rawID, lookupAdvice)
	}
}
