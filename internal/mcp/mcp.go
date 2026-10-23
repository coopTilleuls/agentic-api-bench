// Package mcp serves the framework's MCP tools as JSON-RPC 2.0, over
// streamable HTTP (plain JSON responses, no SSE) and over stdio.
package mcp

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"slices"
	"strings"
	"unicode"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/guide"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
)

const LatestProtocol = "2025-06-18"

var supportedProtocols = []string{"2024-11-05", "2025-03-26", LatestProtocol}

type ValidateArgs struct {
	Definition string `json:"definition,omitempty"`
	Filename   string `json:"filename,omitempty"`
	Query      string `json:"query,omitempty"`
}

type Backend interface {
	Guide(topic string) (string, error)
	Definitions() []*resource.Definition
	Validate(ctx context.Context, args ValidateArgs) apierr.Report
	List(ctx context.Context, d *resource.Definition, req script.Request, params url.Values) (any, error)
	Get(ctx context.Context, d *resource.Definition, req script.Request, id string) (any, error)
	Create(ctx context.Context, d *resource.Definition, req script.Request, body map[string]any) (any, error)
	// Update applies a partial update (op "update") or a delete (op "delete").
	Update(ctx context.Context, d *resource.Definition, op string, req script.Request, id string, body map[string]any) (any, error)
	// Operation runs operation i of d ("operations" in the definition); id
	// is the identifier of an item operation (/authors/{id}).
	Operation(ctx context.Context, d *resource.Definition, i int, req script.Request, id string, body map[string]any) (string, error)
}

// OperationTool names the tool of a dedicated operation: GET /business/export
// gives get_business_export.
func OperationTool(op resource.Operation) string {
	name := strings.ToLower(op.Method)
	for _, part := range strings.FieldsFunc(op.Prefix(), func(r rune) bool { return r == '/' || r == '-' || r == '.' }) {
		name += "_" + part
	}
	if op.IsItem() {
		name += "_item"
		for _, part := range strings.FieldsFunc(op.Suffix(), func(r rune) bool { return r == '/' || r == '-' || r == '.' }) {
			name += "_" + part
		}
	}
	return name
}

type Server struct {
	Backend          Backend
	PerResourceTools bool
	Version          string
}

type rpcRequest struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id,omitempty"`
	Method  string          `json:"method"`
	Params  json.RawMessage `json:"params,omitempty"`
}

type rpcError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

type rpcResponse struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id"`
	Result  any             `json:"result,omitempty"`
	Error   *rpcError       `json:"error,omitempty"`
}

const (
	codeParse          = -32700
	codeInvalidRequest = -32600
	codeMethodNotFound = -32601
	codeInvalidParams  = -32602
)

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		w.Header().Set("Allow", "POST")
		http.Error(w, "MCP endpoint: send JSON-RPC 2.0 requests with POST (no SSE stream is offered)", http.StatusMethodNotAllowed)
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, 4<<20))
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	out := s.Handle(r.Context(), r.Header, body)
	if out == nil {
		w.WriteHeader(http.StatusAccepted)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	_, _ = w.Write(out)
}

// ServeStdio reads newline-delimited JSON-RPC messages until in is closed.
func (s *Server) ServeStdio(ctx context.Context, in io.Reader, out io.Writer) error {
	sc := bufio.NewScanner(in)
	sc.Buffer(make([]byte, 0, 1<<20), 16<<20)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		if resp := s.Handle(ctx, http.Header{}, []byte(line)); resp != nil {
			if _, err := out.Write(append(resp, '\n')); err != nil {
				return err
			}
		}
	}
	return sc.Err()
}

// Handle processes one JSON-RPC message and returns the encoded response, or
// nil for notifications.
func (s *Server) Handle(ctx context.Context, header http.Header, body []byte) []byte {
	var req rpcRequest
	if err := json.Unmarshal(body, &req); err != nil {
		return encode(rpcResponse{JSONRPC: "2.0", ID: json.RawMessage("null"), Error: &rpcError{codeParse, "parse error: " + err.Error()}})
	}
	if req.JSONRPC != "2.0" || req.Method == "" {
		return encode(rpcResponse{JSONRPC: "2.0", ID: idOrNull(req.ID), Error: &rpcError{codeInvalidRequest, "invalid request: expected jsonrpc \"2.0\" and a method"}})
	}
	if len(req.ID) == 0 {
		return nil
	}
	result, rerr := s.dispatch(ctx, header, req)
	return encode(rpcResponse{JSONRPC: "2.0", ID: req.ID, Result: result, Error: rerr})
}

func idOrNull(id json.RawMessage) json.RawMessage {
	if len(id) == 0 {
		return json.RawMessage("null")
	}
	return id
}

func encode(v any) []byte {
	b, _ := json.Marshal(v)
	return b
}

func (s *Server) dispatch(ctx context.Context, header http.Header, req rpcRequest) (any, *rpcError) {
	switch req.Method {
	case "initialize":
		var p struct {
			ProtocolVersion string `json:"protocolVersion"`
		}
		_ = json.Unmarshal(req.Params, &p)
		version := LatestProtocol
		if slices.Contains(supportedProtocols, p.ProtocolVersion) {
			version = p.ProtocolVersion
		}
		return map[string]any{
			"protocolVersion": version,
			"capabilities":    map[string]any{"tools": map[string]any{"listChanged": false}},
			"serverInfo":      map[string]any{"name": "caddy-resource", "version": s.version()},
			"instructions":    "This server builds JSON-LD/Hydra or plain JSON REST APIs over PostgreSQL from declarative resource definitions. Call framework_guide first: the core guide has the workflow, a complete example and the topic index; call it again with a topic when you need details (every error hint names its topic). Use validate after every change to the definitions.",
		}, nil
	case "ping":
		return map[string]any{}, nil
	case "tools/list":
		return map[string]any{"tools": s.tools()}, nil
	case "tools/call":
		var p struct {
			Name      string         `json:"name"`
			Arguments map[string]any `json:"arguments"`
		}
		if err := json.Unmarshal(req.Params, &p); err != nil {
			return nil, &rpcError{codeInvalidParams, "invalid params: " + err.Error()}
		}
		return s.call(ctx, header, p.Name, p.Arguments)
	}
	return nil, &rpcError{codeMethodNotFound, fmt.Sprintf("method %q not found; supported: initialize, ping, tools/list, tools/call", req.Method)}
}

func (s *Server) version() string {
	if s.Version == "" {
		return "dev"
	}
	return s.Version
}

func snake(name string) string {
	var b strings.Builder
	for i, r := range name {
		if unicode.IsUpper(r) {
			if i > 0 {
				b.WriteByte('_')
			}
			r = unicode.ToLower(r)
		}
		b.WriteRune(r)
	}
	return b.String()
}

type tool struct {
	Name        string         `json:"name"`
	Description string         `json:"description"`
	InputSchema map[string]any `json:"inputSchema"`
}

func object(props map[string]any, required ...string) map[string]any {
	s := map[string]any{"type": "object", "properties": props, "additionalProperties": false}
	if len(required) > 0 {
		s["required"] = required
	}
	return s
}

func jsonType(t string) string {
	if t == "datetime" || t == "date" {
		return "string"
	}
	return t
}

// paramProps adds the query parameters of collection reads to a tool schema;
// header parameters come from the MCP request headers.
func paramProps(d *resource.Definition, props map[string]any) {
	for _, p := range resource.DocParameters(d, true) {
		if p.In != "query" {
			continue
		}
		sch := map[string]any{"type": jsonType(p.Type)}
		if p.Format != "" {
			sch["format"] = p.Format
		}
		if p.Description != "" {
			sch["description"] = p.Description
		}
		if p.Min != nil {
			sch["minimum"] = *p.Min
		}
		if p.Max != nil {
			sch["maximum"] = *p.Max
		}
		if len(p.Enum) > 0 {
			sch["enum"] = p.Enum
		}
		props[p.Name] = sch
	}
}

func guideDescription() string {
	var b strings.Builder
	b.WriteString("Returns the guide of this framework. Without arguments: the core guide (fast workflow, a complete example, the most used keys). With topic: that one topic. Read the core guide before writing anything. Topics:")
	for _, t := range guide.Topics(guide.Options{MCP: true}) {
		fmt.Fprintf(&b, " %s (%s)", t.Name, strings.TrimSuffix(t.Summary, "."))
		b.WriteString(";")
	}
	return b.String()
}

func (s *Server) tools() []tool {
	tools := []tool{
		{Name: "framework_guide", Description: guideDescription(), InputSchema: object(map[string]any{
			"topic": map[string]any{"type": "string", "description": "One topic name. Omit it for the core guide."},
		})},
		{Name: "validate", Description: "Validates resource definitions statically and against the database, returning {valid, errors[], warnings[]} where each error has code, resource, pointer, message and hint. Without arguments it re-reads the definitions directory from disk (so it sees your latest edits without a restart). With `definition` it validates that single definition source instead; pass `query` with the source of the query file it references.", InputSchema: object(map[string]any{
			"definition": map[string]any{"type": "string", "description": "Source of one definition file (JSON, or TypeScript using export default defineResource({...}))."},
			"filename":   map[string]any{"type": "string", "description": "File name of that definition, e.g. Book.json or Book.ts. Defaults to definition.json or definition.ts depending on the source."},
			"query":      map[string]any{"type": "string", "description": "Source of the query file named by the definition's \"query\" key."},
		})},
		{Name: "list_resources", Description: "Lists the resources currently served: name, path, source (table or query), itemsPerPage and properties.", InputSchema: object(map[string]any{})},
	}
	if !s.PerResourceTools {
		return tools
	}
	for _, d := range s.Backend.Definitions() {
		for _, op := range d.Ops {
			props := map[string]any{}
			var required []string
			od := d.ForOperation(op)
			if op.IsItem() {
				props["id"] = map[string]any{"type": []string{jsonType(d.Identifier().Type), "string"}, "description": "Value of the identifier property " + d.Identifier().Name + "."}
				required = append(required, "id")
			}
			if op.Reads() && !op.IsItem() {
				paramProps(od, props)
			} else if op.Method != http.MethodDelete && !op.Reads() {
				for _, p := range od.InputProps() {
					props[p.Name] = map[string]any{"type": []string{jsonType(p.Type), "null"}}
					if p.Required && op.Method != http.MethodPatch {
						required = append(required, p.Name)
					}
				}
			}
			format := op.Format
			if format == "" {
				format = "negotiated"
			}
			desc := fmt.Sprintf("%s %s (%s output).", op.Method, op.Path, format)
			if op.Description != "" {
				desc += " " + op.Description
			}
			tools = append(tools, tool{Name: OperationTool(op), Description: desc, InputSchema: object(props, required...)})
		}
		if len(d.Ops) > 0 {
			continue
		}
		props := map[string]any{}
		paramProps(d, props)
		id := d.Identifier()
		idSchema := map[string]any{"type": []string{jsonType(id.Type), "string"}, "description": "Value of the identifier property " + id.Name + "."}
		ops := d.Operations()
		writeProps := func(withID bool) (map[string]any, []string) {
			props := map[string]any{}
			var required []string
			if withID {
				props["id"] = idSchema
			}
			for _, p := range d.InputProps() {
				if withID && p.Identifier {
					continue
				}
				props[p.Name] = map[string]any{"type": []string{jsonType(p.Type), "null"}}
				if p.Required && !withID {
					required = append(required, p.Name)
				}
			}
			return props, required
		}
		if slices.Contains(ops, "create") {
			props, required := writeProps(false)
			tools = append(tools, tool{Name: "create_" + snake(d.Name), Description: fmt.Sprintf("POST %s: creates a %s and returns it as read back through the resource.", d.Path, d.Name), InputSchema: object(props, required...)})
		}
		if slices.Contains(ops, "update") {
			props, _ := writeProps(true)
			tools = append(tools, tool{Name: "update_" + snake(d.Name), Description: fmt.Sprintf("PATCH %s/{%s}: updates the given properties of a %s.", d.Path, id.Name, d.Name), InputSchema: object(props, "id")})
		}
		if slices.Contains(ops, "delete") {
			tools = append(tools, tool{Name: "delete_" + snake(d.Name), Description: fmt.Sprintf("DELETE %s/{%s}.", d.Path, id.Name), InputSchema: object(map[string]any{"id": idSchema}, "id")})
		}
		tools = append(tools,
			tool{Name: "list_" + snake(d.Name), Description: fmt.Sprintf("GET %s: the %s collection as JSON-LD/Hydra (member, totalItems, view).", d.Path, d.Name), InputSchema: object(props)},
			tool{Name: "get_" + snake(d.Name), Description: fmt.Sprintf("GET %s/{%s}: one %s as JSON-LD.", d.Path, id.Name, d.Name), InputSchema: object(map[string]any{"id": map[string]any{"type": []string{jsonType(id.Type), "string"}, "description": "Value of the identifier property " + id.Name + "."}}, "id")},
		)
	}
	return tools
}

func textResult(v any, isError bool) map[string]any {
	var text string
	if s, ok := v.(string); ok {
		text = s
	} else {
		b, _ := json.MarshalIndent(v, "", "  ")
		text = string(b)
	}
	return map[string]any{"content": []map[string]any{{"type": "text", "text": text}}, "isError": isError}
}

func errorResult(err error) map[string]any {
	var list apierr.List
	if errors.As(err, &list) {
		return textResult(map[string]any{"errors": list}, true)
	}
	e, ok := apierr.As(err)
	if !ok {
		e = &apierr.Error{Code: apierr.InternalError, Message: err.Error()}
	}
	return textResult(e, true)
}

func (s *Server) call(ctx context.Context, header http.Header, name string, args map[string]any) (any, *rpcError) {
	switch name {
	case "framework_guide":
		topic, _ := args["topic"].(string)
		text, err := s.Backend.Guide(topic)
		if err != nil {
			return textResult(err.Error(), true), nil
		}
		return textResult(text, false), nil
	case "validate":
		var a ValidateArgs
		b, _ := json.Marshal(args)
		_ = json.Unmarshal(b, &a)
		report := s.Backend.Validate(ctx, a)
		return textResult(report, !report.Valid), nil
	case "list_resources":
		var out []map[string]any
		for _, d := range s.Backend.Definitions() {
			props := map[string]any{}
			for _, p := range d.Properties {
				props[p.Name] = p
			}
			entry := map[string]any{"name": d.Name, "path": d.Path, "itemsPerPage": d.ItemsPerPage, "properties": props, "file": d.File}
			if d.Table != "" {
				entry["table"] = d.Table
			}
			if d.Query != "" {
				entry["query"] = d.Query
			}
			var params []map[string]any
			for _, p := range resource.DocParameters(d, true) {
				params = append(params, map[string]any{"name": p.Name, "in": p.In, "type": p.Type, "required": p.Required, "description": p.Description})
			}
			if params != nil {
				entry["parameters"] = params
			}
			if len(d.Ops) > 0 {
				var ops []string
				for _, op := range d.Ops {
					ops = append(ops, op.Method+" "+op.Path)
				}
				entry["operations"] = ops
			}
			out = append(out, entry)
		}
		if out == nil {
			return textResult("no resources are loaded", false), nil
		}
		return textResult(out, false), nil
	}
	for _, d := range s.Backend.Definitions() {
		if !s.PerResourceTools {
			break
		}
		for i, op := range d.Ops {
			if name != OperationTool(op) {
				continue
			}
			req := script.Request{Method: op.Method, Path: op.Path, Query: url.Values{}, Header: header}
			body := map[string]any{}
			var id string
			for k, v := range args {
				switch {
				case op.IsItem() && k == "id":
					id = idArg(v)
					req.Path = op.Resolve(id)
				case op.Reads():
					req.Query.Set(k, fmt.Sprint(v))
				default:
					body[k] = v
				}
			}
			req.RawQuery = req.Query.Encode()
			out, err := s.Backend.Operation(ctx, d, i, req, id, body)
			if err != nil {
				return errorResult(err), nil
			}
			if out == "" {
				out = "done (no content)"
			}
			return textResult(out, false), nil
		}
		req := script.Request{Method: http.MethodGet, Path: d.Path, Query: url.Values{}, Header: header}
		switch name {
		case "list_" + snake(d.Name):
			params := url.Values{}
			for k, v := range args {
				params.Set(k, fmt.Sprint(v))
			}
			req.Query, req.RawQuery = params, params.Encode()
			doc, err := s.Backend.List(ctx, d, req, params)
			if err != nil {
				return errorResult(err), nil
			}
			return textResult(doc, false), nil
		case "get_" + snake(d.Name):
			id := idArg(args["id"])
			req.Path = d.Path + "/" + id
			doc, err := s.Backend.Get(ctx, d, req, id)
			if err != nil {
				return errorResult(err), nil
			}
			return textResult(doc, false), nil
		case "create_" + snake(d.Name):
			req.Method = http.MethodPost
			doc, err := s.Backend.Create(ctx, d, req, args)
			if err != nil {
				return errorResult(err), nil
			}
			return textResult(doc, false), nil
		case "update_" + snake(d.Name), "delete_" + snake(d.Name):
			op, method := "update", http.MethodPatch
			if strings.HasPrefix(name, "delete_") {
				op, method = "delete", http.MethodDelete
			}
			id := idArg(args["id"])
			body := map[string]any{}
			for k, v := range args {
				if k != "id" {
					body[k] = v
				}
			}
			req.Method, req.Path = method, d.Path+"/"+id
			doc, err := s.Backend.Update(ctx, d, op, req, id, body)
			if err != nil {
				return errorResult(err), nil
			}
			if doc == nil {
				return textResult(fmt.Sprintf("%s %s deleted", d.Name, id), false), nil
			}
			return textResult(doc, false), nil
		}
	}
	return nil, &rpcError{codeInvalidParams, fmt.Sprintf("unknown tool %q; call tools/list", name)}
}

func idArg(v any) string {
	if f, ok := v.(float64); ok && f == float64(int64(f)) {
		return fmt.Sprint(int64(f))
	}
	return fmt.Sprint(v)
}
