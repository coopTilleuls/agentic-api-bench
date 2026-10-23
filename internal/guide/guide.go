package guide

import (
	"bytes"
	"embed"
	"encoding/json"
	"fmt"
	"maps"
	"slices"
	"strconv"
	"strings"
	"text/template"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

//go:embed topics/*.md.tmpl
var files embed.FS

var tmpl = template.Must(template.ParseFS(files, "topics/*.md.tmpl"))

type Options struct {
	MCP bool
}

type Topic struct {
	Name    string
	Summary string
}

var topics = []Topic{
	{"definitions", "definition files, every key, types, PostgreSQL column mapping"},
	{"queries", "query scripts, SQL wrapping, nested output, ctx.resource() helpers"},
	{"parameters", "filters, sorting, page size, header parameters, handlers"},
	{"commands", "writes, command scripts, ctx.reject, CLI tools (introspect, sql, request, eval)"},
	{"operations", "dedicated endpoints (reports, exports, actions), per-operation output"},
	{"formats", "JSON-LD, JSON, CSV, negotiation, envelope, error bodies"},
	{"scripts", "the ctx host API, stdlib, raw responses, TypeScript vs Starlark"},
	{"server", "Caddyfile options, reserved paths, running and reloading"},
	{"errors", "validation report and every error code"},
	{"mcp", "the MCP endpoint and its tools"},
	{"schema", "outline of the definition JSON Schema"},
}

func Topics(opts Options) []Topic {
	var out []Topic
	for _, t := range topics {
		if t.Name == "mcp" && !opts.MCP {
			continue
		}
		out = append(out, t)
	}
	return out
}

type codeGroup struct {
	Label string
	Codes string
}

// codeTables lists every registry code: those a client meets at request time
// by HTTP status, the definition and script errors (HTTP 500, explained by the
// hint of the validation report) by topic. The per-code summary and fix of the
// registry are not repeated: every raised error carries its own hint.
func codeTables() (byStatus, byTopic []codeGroup) {
	statuses := map[int][]string{}
	topics := map[string][]string{}
	for _, c := range apierr.AllCodes() {
		info := apierr.Registry[c]
		name := "`" + string(c) + "`"
		if info.Status < 500 || info.Status == 502 || info.Status == 503 {
			statuses[info.Status] = append(statuses[info.Status], name)
			continue
		}
		topics[info.Topic] = append(topics[info.Topic], name)
	}
	for _, status := range slices.Sorted(maps.Keys(statuses)) {
		byStatus = append(byStatus, codeGroup{Label: strconv.Itoa(status), Codes: strings.Join(statuses[status], ", ")})
	}
	for _, topic := range slices.Sorted(maps.Keys(topics)) {
		byTopic = append(byTopic, codeGroup{Label: topic, Codes: strings.Join(topics[topic], ", ")})
	}
	return byStatus, byTopic
}

// Render returns the core guide for "", else the one topic named. There is no
// way to load every topic at once: agents re-read what they load on each turn.
func Render(name string, opts Options) (string, error) {
	if name == "" {
		return execute("core", opts), nil
	}
	var index strings.Builder
	for _, t := range Topics(opts) {
		if t.Name == name {
			return execute(name, opts), nil
		}
		fmt.Fprintf(&index, "\n  %s: %s", t.Name, t.Summary)
	}
	return "", fmt.Errorf("unknown guide topic %q; pick one:%s", name, index.String())
}

func execute(name string, opts Options) string {
	byStatus, byTopic := codeTables()
	var reserved []string
	for _, p := range resource.ReservedPaths {
		if p == "/mcp" && !opts.MCP {
			continue
		}
		reserved = append(reserved, "`"+p+"`")
	}
	var b bytes.Buffer
	err := tmpl.ExecuteTemplate(&b, name+".md.tmpl", map[string]any{
		"MCP":      opts.MCP,
		"ByStatus": byStatus,
		"ByTopic":  byTopic,
		"Topics":   Topics(opts),
		"Reserved": strings.Join(reserved, ", "),
		"Schema":   schemaOutline(),
		"Ident":    identPattern,
	})
	if err != nil {
		panic(err)
	}
	return b.String()
}

const identPattern = "^[A-Za-z_][A-Za-z0-9_]*$"

func schemaOutline() string {
	var doc map[string]any
	if err := json.Unmarshal(resource.SchemaJSON, &doc); err != nil {
		panic(err)
	}
	var b strings.Builder
	outlineObject(&b, "definition", doc)
	defs, _ := doc["$defs"].(map[string]any)
	for _, name := range slices.Sorted(maps.Keys(defs)) {
		outlineObject(&b, "<"+name+">", defs[name].(map[string]any))
	}
	return b.String()
}

func outlineObject(b *strings.Builder, title string, node map[string]any) {
	fmt.Fprintf(b, "%s\n", title)
	required, _ := node["required"].([]any)
	props, _ := node["properties"].(map[string]any)
	for _, key := range slices.Sorted(maps.Keys(props)) {
		if key == "description" || key == "$schema" {
			continue
		}
		mark := ""
		if slices.Contains(required, any(key)) {
			mark = "*"
		}
		fmt.Fprintf(b, "  %s%s: %s\n", key, mark, outlineType(props[key].(map[string]any)))
	}
}

func outlineType(n map[string]any) string {
	if ref, ok := n["$ref"].(string); ok {
		return "<" + ref[strings.LastIndex(ref, "/")+1:] + ">"
	}
	if c, ok := n["const"]; ok {
		return fmt.Sprintf("%v", c)
	}
	if alts, ok := n["oneOf"].([]any); ok {
		var parts []string
		for _, a := range alts {
			parts = append(parts, outlineType(a.(map[string]any)))
		}
		return strings.Join(parts, " | ")
	}
	if enum, ok := n["enum"].([]any); ok {
		return enumList(enum)
	}
	typ, _ := n["type"].(string)
	switch typ {
	case "array":
		out := "array"
		if items, ok := n["items"].(map[string]any); ok {
			out += " of " + outlineType(items)
		}
		return out
	case "object":
		if add, ok := n["additionalProperties"].(map[string]any); ok {
			return "map of " + outlineType(add)
		}
		if props, ok := n["properties"].(map[string]any); ok {
			var parts []string
			for _, k := range slices.Sorted(maps.Keys(props)) {
				parts = append(parts, k+": "+outlineType(props[k].(map[string]any)))
			}
			return "{" + strings.Join(parts, ", ") + "}"
		}
	}
	out := typ
	if lo, ok := n["minimum"]; ok {
		out += fmt.Sprintf(" min %v", lo)
	}
	if hi, ok := n["maximum"]; ok {
		out += fmt.Sprintf(" max %v", hi)
	}
	if def, ok := n["default"]; ok {
		out += fmt.Sprintf(" default %v", def)
	}
	if p, ok := n["pattern"].(string); ok && p == identPattern {
		return "ident"
	}
	return out
}

func enumList(enum []any) string {
	parts := make([]string, len(enum))
	for i, e := range enum {
		parts[i] = fmt.Sprint(e)
	}
	return strings.Join(parts, "|")
}
