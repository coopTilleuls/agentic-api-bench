package httpapi

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/url"
	"slices"
	"sort"
	"strconv"
	"strings"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

const HydraNS = "http://www.w3.org/ns/hydra/core#"

type kv struct {
	K string
	V any
}

// Obj is a JSON object that keeps key order, so documents read like API
// Platform's (@context, @id, @type first, then properties as declared).
type Obj []kv

func (o Obj) MarshalJSON() ([]byte, error) {
	var b bytes.Buffer
	b.WriteByte('{')
	for i, e := range o {
		if i > 0 {
			b.WriteByte(',')
		}
		k, _ := Marshal(e.K)
		b.Write(k)
		b.WriteByte(':')
		v, err := Marshal(e.V)
		if err != nil {
			return nil, err
		}
		b.Write(v)
	}
	b.WriteByte('}')
	return b.Bytes(), nil
}

// Marshal encodes without HTML escaping so IRIs keep a literal "&".
func Marshal(v any) ([]byte, error) {
	var b bytes.Buffer
	enc := json.NewEncoder(&b)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return nil, err
	}
	return bytes.TrimRight(b.Bytes(), "\n"), nil
}

func itemDoc(d *resource.Definition, item resource.Item, withContext bool) Obj {
	o := Obj{}
	if withContext {
		o = append(o, kv{"@context", "/contexts/" + d.Name})
	}
	if hasItemIRIs(d) {
		if id := item[d.Identifier().Name]; id != nil {
			o = append(o, kv{"@id", itemPath(d, id)})
		}
	}
	o = append(o, kv{"@type", d.Name})
	for _, p := range d.Output() {
		o = append(o, kv{p.Name, item[p.Name]})
	}
	return o
}

func pageURL(u *url.URL, page int) string {
	q := u.Query()
	q.Set("page", strconv.Itoa(page))
	return u.Path + "?" + q.Encode()
}

func collectionDoc(d *resource.Definition, items []resource.Item, total int64, page, itemsPerPage int, u *url.URL) Obj {
	members := make([]Obj, 0, len(items))
	for _, it := range items {
		members = append(members, itemDoc(d, it, false))
	}
	doc := Obj{
		{"@context", "/contexts/" + d.Name},
		{"@id", u.Path},
		{"@type", "Collection"},
		{"totalItems", total},
		{"member", members},
	}

	if itemsPerPage < 1 {
		itemsPerPage = d.ItemsPerPage
	}
	lastPage := int((total + int64(itemsPerPage) - 1) / int64(itemsPerPage))
	lastPage = max(lastPage, 1)
	params := u.Query()
	params.Del("page")
	paginated := lastPage > 1
	if paginated || len(params) > 0 {
		view := Obj{}
		if paginated {
			view = append(view, kv{"@id", pageURL(u, page)}, kv{"@type", "PartialCollectionView"}, kv{"first", pageURL(u, 1)}, kv{"last", pageURL(u, lastPage)})
			if page > 1 {
				view = append(view, kv{"previous", pageURL(u, page-1)})
			}
			if page < lastPage {
				view = append(view, kv{"next", pageURL(u, page+1)})
			}
		} else {
			view = append(view, kv{"@id", u.Path + "?" + params.Encode()}, kv{"@type", "PartialCollectionView"})
		}
		doc = append(doc, kv{"view", view})
	}

	var search []resource.DocParam
	for _, p := range resource.DocParameters(d, true) {
		if p.In == "query" && p.Name != "page" {
			search = append(search, p)
		}
	}
	if len(search) > 0 {
		vars := make([]string, len(search))
		mapping := make([]Obj, len(search))
		for i, p := range search {
			vars[i] = p.Name
			mapping[i] = Obj{{"@type", "IriTemplateMapping"}, {"variable", p.Name}}
			if p.Property != "" {
				mapping[i] = append(mapping[i], kv{"property", p.Property})
			}
			mapping[i] = append(mapping[i], kv{"required", p.Required})
		}
		doc = append(doc, kv{"search", Obj{
			{"@type", "IriTemplate"},
			{"template", fmt.Sprintf("%s{?%s}", u.Path, strings.Join(vars, ","))},
			{"variableRepresentation", "BasicRepresentation"},
			{"mapping", mapping},
		}})
	}
	return doc
}

func contextDoc(d *resource.Definition, base string) Obj {
	ctx := Obj{{"@vocab", base + "/docs.jsonld#"}, {"hydra", HydraNS}}
	for _, p := range d.Properties {
		if p.Identifier {
			continue
		}
		ctx = append(ctx, kv{p.Name, d.Name + "/" + p.Name})
	}
	return Obj{{"@context", ctx}}
}

func entrypointName(d *resource.Definition) string {
	return strings.ToLower(d.Name[:1]) + d.Name[1:]
}

func entrypointContextDoc(set *resource.Set, base string) Obj {
	ctx := Obj{{"@vocab", base + "/docs.jsonld#"}, {"hydra", HydraNS}}
	for _, d := range set.Defs {
		if len(d.Ops) > 0 {
			continue
		}
		ctx = append(ctx, kv{entrypointName(d), Obj{{"@id", "Entrypoint/" + entrypointName(d)}, {"@type", "@id"}}})
	}
	return Obj{{"@context", ctx}}
}

func entrypointDoc(set *resource.Set) Obj {
	o := Obj{{"@context", "/contexts/Entrypoint"}, {"@id", "/"}, {"@type", "Entrypoint"}}
	for _, d := range set.Defs {
		if len(d.Ops) > 0 {
			continue
		}
		o = append(o, kv{entrypointName(d), d.Path})
	}
	return o
}

func problemDoc(e *apierr.Error) Obj {
	status := e.Status()
	o := Obj{
		{"type", fmt.Sprintf("/errors/%d", status)},
		{"title", "An error occurred"},
		{"status", status},
		{"detail", e.Message},
		{"code", e.Code},
	}
	if hint := e.HintText(); hint != "" {
		o = append(o, kv{"hint", hint})
	}
	if e.Resource != "" {
		o = append(o, kv{"resource", e.Resource})
	}
	if e.Pointer != "" {
		o = append(o, kv{"pointer", e.Pointer})
	}
	return o
}

func openAPIType(p resource.Property) Obj {
	switch p.Type {
	case "datetime":
		return Obj{{"type", []string{"string", "null"}}, {"format", "date-time"}}
	case "date":
		return Obj{{"type", []string{"string", "null"}}, {"format", "date"}}
	case "object":
		props := Obj{}
		for _, sub := range p.Properties {
			props = append(props, kv{sub.Name, openAPIType(sub)})
		}
		return Obj{{"type", []string{"object", "null"}}, {"properties", props}}
	case "array":
		o := Obj{{"type", []string{"array", "null"}}}
		if p.Items != nil {
			o = append(o, kv{"items", openAPIType(*p.Items)})
		}
		return o
	default:
		return Obj{{"type", []string{p.Type, "null"}}}
	}
}

func outputSchema(d *resource.Definition) Obj {
	props := Obj{}
	if hasItemIRIs(d) {
		props = append(props, kv{"@id", Obj{{"type", "string"}, {"format", "iri-reference"}, {"readOnly", true}}}, kv{"@type", Obj{{"type", "string"}, {"readOnly", true}}})
	}
	var required []string
	for _, p := range d.Output() {
		sch := openAPIType(p)
		if p.ReadOnly {
			sch = append(sch, kv{"readOnly", true})
		}
		if p.Description != "" {
			sch = append(sch, kv{"description", p.Description})
		}
		if p.Required {
			required = append(required, p.Name)
		}
		props = append(props, kv{p.Name, sch})
	}
	schema := Obj{{"type", "object"}}
	if d.Description != "" {
		schema = append(schema, kv{"description", d.Description})
	}
	schema = append(schema, kv{"properties", props})
	if len(required) > 0 {
		schema = append(schema, kv{"required", required})
	}
	return schema
}

func docParams(d *resource.Definition, collection bool) []Obj {
	var out []Obj
	for _, p := range resource.DocParameters(d, collection) {
		sch := Obj{{"type", p.Type}}
		if p.Format != "" {
			sch = append(sch, kv{"format", p.Format})
		}
		if p.Min != nil {
			sch = append(sch, kv{"minimum", *p.Min})
		}
		if p.Max != nil {
			sch = append(sch, kv{"maximum", *p.Max})
		}
		if len(p.Enum) > 0 {
			sch = append(sch, kv{"enum", p.Enum})
		}
		o := Obj{{"name", p.Name}, {"in", p.In}, {"required", p.Required}}
		if p.Description != "" {
			o = append(o, kv{"description", p.Description})
		}
		out = append(out, append(o, kv{"schema", sch}))
	}
	if out == nil {
		out = []Obj{}
	}
	return out
}

func openAPIDoc(set *resource.Set, out Output) Obj {
	srv := &Server{Output: out}
	paths := Obj{}
	schemas := Obj{}
	defs := append([]*resource.Definition(nil), set.Defs...)
	sort.Slice(defs, func(i, j int) bool { return defs[i].Name < defs[j].Name })
	errorSchema := func(d *resource.Definition) Obj {
		shape := srv.errorShape(d)
		if len(shape) == 0 {
			return Obj{{"description", "Error"}, {"content", Obj{{"application/problem+json", Obj{{"schema", Obj{{"$ref", "#/components/schemas/Problem"}}}}}}}}
		}
		props := Obj{}
		for _, k := range sortedShape(shape) {
			t := "string"
			if shape[k] == "status" {
				t = "integer"
			}
			props = append(props, kv{k, Obj{{"type", t}, {"description", "Error " + shape[k] + "."}}})
		}
		return Obj{{"description", "Error"}, {"content", Obj{{FormatJSON, Obj{{"schema", Obj{{"type", "object"}, {"properties", props}}}}}}}}
	}
	content := func(d *resource.Definition, ref Obj, collection bool) Obj {
		c := Obj{}
		for _, name := range srv.formats(d) {
			f := formatByName(name)
			var sch Obj
			switch {
			case name == "csv":
				sch = Obj{{"type", "string"}}
			case !collection:
				sch = ref
			case name == "jsonld":
				sch = Obj{{"type", "object"}, {"properties", Obj{{"member", Obj{{"type", "array"}, {"items", ref}}}, {"totalItems", Obj{{"type", "integer"}, {"minimum", 0}}}, {"view", Obj{{"type", "object"}}}, {"search", Obj{{"type", "object"}}}}}}
			default:
				env := srv.envelope(d)
				if len(env) == 0 {
					sch = Obj{{"type", "array"}, {"items", ref}}
					break
				}
				props := Obj{}
				for _, k := range resource.EnvelopeKeys {
					name, ok := env[k]
					if !ok {
						continue
					}
					if k == "items" {
						props = append(props, kv{name, Obj{{"type", "array"}, {"items", ref}}})
					} else {
						props = append(props, kv{name, Obj{{"type", "integer"}}})
					}
				}
				sch = Obj{{"type", "object"}, {"properties", props}}
			}
			c = append(c, kv{f.MediaType(), Obj{{"schema", sch}}})
		}
		return c
	}
	addPath := func(path, method string, op Obj) {
		for i, e := range paths {
			if e.K == path {
				paths[i].V = append(e.V.(Obj), kv{method, op})
				return
			}
		}
		paths = append(paths, kv{path, Obj{{method, op}}})
	}
	for _, d := range defs {
		views := []*resource.Definition{d}
		if len(d.Ops) > 0 {
			views = nil
			for _, op := range d.Ops {
				views = append(views, d.ForOperation(op))
			}
		}
		for vi, v := range views {
			name := d.Name
			if v.OutputNames != nil {
				name = d.Name + pascal(v.Current.Method+v.Current.Path)
			}
			if vi == 0 || v.OutputNames != nil {
				schemas = append(schemas, kv{name, outputSchema(v)})
			}
			ref := Obj{{"$ref", "#/components/schemas/" + name}}
			if v.OutputNames == nil {
				ref = Obj{{"$ref", "#/components/schemas/" + d.Name}}
			}
			inputRef := ref
			if v.Input != nil {
				inName := d.Name + "Input"
				if v.Current != nil && v.Current.Input != nil {
					inName = d.Name + pascal(v.Current.Method+v.Current.Path) + "Input"
				}
				if !slices.ContainsFunc(schemas, func(e kv) bool { return e.K == inName }) {
					schemas = append(schemas, kv{inName, inputSchema(v.Input)})
				}
				inputRef = Obj{{"$ref", "#/components/schemas/" + inName}}
			}
			id := d.Identifier()
			idParam := Obj{{"name", id.Name}, {"in", "path"}, {"required", true}, {"schema", Obj{{"type", id.Type}}}}
			body := Obj{{"required", true}, {"content", Obj{{FormatJSON, Obj{{"schema", inputRef}}}}}}
			type entry struct {
				method, path, id string
				item             bool
			}
			var entries []entry
			if v.Current != nil {
				entries = append(entries, entry{v.Current.Method, v.Current.Path, strings.ToLower(v.Current.Method) + pascal(v.Current.Path), v.Current.IsItem()})
			} else {
				ops := d.Operations()
				itemPath := d.Path + "/{" + id.Name + "}"
				entries = append(entries, entry{"GET", d.Path, "get" + d.Name + "Collection", false})
				if slices.Contains(ops, "create") {
					entries = append(entries, entry{"POST", d.Path, "post" + d.Name, false})
				}
				entries = append(entries, entry{"GET", itemPath, "get" + d.Name, true})
				if slices.Contains(ops, "update") {
					entries = append(entries, entry{"PUT", itemPath, "put" + d.Name, true}, entry{"PATCH", itemPath, "patch" + d.Name, true})
				}
				if slices.Contains(ops, "delete") {
					entries = append(entries, entry{"DELETE", itemPath, "delete" + d.Name, true})
				}
			}
			for _, e := range entries {
				kind := e.method
				if v.Current != nil && v.Current.Reads() {
					kind = "GET"
				}
				params := docParams(v, kind == "GET" && !e.item)
				if e.item {
					params = append([]Obj{idParam}, params...)
				}
				op := Obj{{"operationId", e.id}}
				if v.Current != nil && v.Current.Description != "" {
					op = append(op, kv{"summary", v.Current.Description})
				}
				op = append(op, kv{"parameters", params})
				responses := Obj{}
				switch kind {
				case "GET":
					responses = append(responses, kv{"200", Obj{{"description", d.Name}, {"content", content(v, ref, !e.item)}}})
				case "POST":
					if !v.IgnoresBody() {
						op = append(op, kv{"requestBody", body})
					}
					if e.item {
						responses = append(responses, kv{"200", Obj{{"description", d.Name}, {"content", content(v, ref, false)}}}, kv{"204", Obj{{"description", "Done, not visible any more"}}})
					} else {
						responses = append(responses, kv{"201", Obj{{"description", d.Name + " created"}, {"content", content(v, ref, false)}}})
					}
				case "PUT", "PATCH":
					if !v.IgnoresBody() {
						op = append(op, kv{"requestBody", body})
					}
					responses = append(responses, kv{"200", Obj{{"description", d.Name + " updated"}, {"content", content(v, ref, false)}}}, kv{"204", Obj{{"description", "Updated, not visible any more"}}})
				case "DELETE":
					responses = append(responses, kv{"204", Obj{{"description", d.Name + " deleted"}}})
				}
				responses = append(responses, kv{"4XX", errorSchema(v)})
				op = append(op, kv{"responses", responses})
				addPath(e.path, strings.ToLower(e.method), op)
			}
		}
	}
	schemas = append(schemas, kv{"Problem", Obj{{"type", "object"}, {"properties", Obj{
		{"type", Obj{{"type", "string"}}},
		{"title", Obj{{"type", "string"}}},
		{"status", Obj{{"type", "integer"}}},
		{"detail", Obj{{"type", "string"}}},
		{"code", Obj{{"type", "string"}}},
		{"hint", Obj{{"type", "string"}}},
		{"pointer", Obj{{"type", "string"}}},
	}}}})
	return Obj{
		{"openapi", "3.1.0"},
		{"info", Obj{{"title", "API"}, {"version", "0.0.0"}}},
		{"paths", paths},
		{"components", Obj{{"schemas", schemas}}},
	}
}

func sortedShape(shape resource.ErrorShape) []string {
	keys := make([]string, 0, len(shape))
	for k := range shape {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

func pascal(path string) string {
	var b strings.Builder
	for _, part := range strings.FieldsFunc(path, func(r rune) bool { return r == '/' || r == '-' || r == '_' || r == '.' || r == '{' || r == '}' }) {
		b.WriteString(strings.ToUpper(part[:1]) + part[1:])
	}
	return b.String()
}

func inputSchema(props []resource.Property) Obj {
	o := Obj{}
	var required []string
	for _, p := range props {
		s := openAPIType(p)
		if p.Description != "" {
			s = append(s, kv{"description", p.Description})
		}
		o = append(o, kv{p.Name, s})
		if p.Required {
			required = append(required, p.Name)
		}
	}
	schema := Obj{{"type", "object"}, {"properties", o}, {"additionalProperties", false}}
	if len(required) > 0 {
		schema = append(schema, kv{"required", required})
	}
	return schema
}
