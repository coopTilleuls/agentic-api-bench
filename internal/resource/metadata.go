package resource

import (
	"bytes"
	"encoding/json"
	"fmt"
	"slices"
	"sort"
	"strings"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

type Relation struct {
	Resource   string `json:"resource"`
	Column     string `json:"column,omitempty"`
	ForeignKey string `json:"foreignKey,omitempty"`
	Many       bool   `json:"many,omitempty"`
}

// Parameter maps a query string or header value to the response, after API
// Platform's QueryParameter/HeaderParameter.
type Parameter struct {
	Name        string        `json:"-"`
	In          string        `json:"in"`
	Type        string        `json:"type,omitempty"`
	Required    bool          `json:"required,omitempty"`
	Min         *float64      `json:"min,omitempty"`
	Max         *float64      `json:"max,omitempty"`
	Enum        []string      `json:"enum,omitempty"`
	Status      int           `json:"status,omitempty"`
	Filter      string        `json:"filter,omitempty"`
	Property    string        `json:"property,omitempty"`
	Properties  []string      `json:"properties,omitempty"`
	Pagination  string        `json:"pagination,omitempty"`
	Handler     string        `json:"handler,omitempty"`
	Description string        `json:"description,omitempty"`
	HandlerMod  script.Module `json:"-"`

	column string
	// sortColumns maps each sortable property (order filter) to its column.
	sortColumns map[string]string
}

// Column is the column of the query result the filter applies to:
// the property name, or <object>_<nested> for author.name.
func (p Parameter) Column() string {
	return p.column
}

func (p Parameter) SortColumn(property string) (string, bool) {
	c, ok := p.sortColumns[property]
	return c, ok
}

func (p Parameter) ErrorStatus() int {
	if p.Status != 0 {
		return p.Status
	}
	return 400
}

// AppliesTo reports whether the parameter is read for a request: header
// parameters apply to every operation, query parameters to collection reads.
func (p Parameter) AppliesTo(collection bool) bool {
	return p.In == "header" || collection
}

var FilterNames = []string{"exact", "partial", "ipartial", "range", "date", "boolean", "order"}

var RangeOperators = []string{"gt", "gte", "lt", "lte"}

var DateOperators = []string{"after", "before", "strictly_after", "strictly_before"}

// ErrorShape is nil to inherit the site setting, empty for problem+json, or
// output field -> error field ({"error": "message"}).
type ErrorShape map[string]string

func (e *ErrorShape) UnmarshalJSON(b []byte) error {
	var s string
	if json.Unmarshal(b, &s) == nil {
		*e = ErrorShape{}
		return nil
	}
	var m map[string]string
	if err := json.Unmarshal(b, &m); err != nil {
		return err
	}
	*e = m
	return nil
}

var EnvelopeKeys = []string{"items", "total", "page", "itemsPerPage", "pages"}

var ErrorFields = []string{"message", "code", "status", "pointer", "hint", "resource"}

func (p *Property) UnmarshalJSON(b []byte) error {
	type plain Property
	var raw struct {
		plain
		Properties json.RawMessage `json:"properties"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*p = Property(raw.plain)
	p.Properties = orderedProperties(raw.Properties)
	return nil
}

func (p Property) MarshalJSON() ([]byte, error) {
	type plain Property
	var b bytes.Buffer
	enc, err := json.Marshal(plain(p))
	if err != nil {
		return nil, err
	}
	b.Write(enc)
	if len(p.Properties) == 0 {
		return b.Bytes(), nil
	}
	b.Truncate(b.Len() - 1)
	b.WriteString(`,"properties":{`)
	for i, sub := range p.Properties {
		if i > 0 {
			b.WriteByte(',')
		}
		k, _ := json.Marshal(sub.Name)
		v, err := json.Marshal(sub)
		if err != nil {
			return nil, err
		}
		b.Write(k)
		b.WriteByte(':')
		b.Write(v)
	}
	b.WriteString("}}")
	return b.Bytes(), nil
}

// ColumnName is the table column of the property.
func (p Property) ColumnName() string {
	if p.Column != "" {
		return p.Column
	}
	return defaultColumn(p.Name)
}

// Scalar reports whether the property holds one SQL value.
func (p Property) Scalar() bool {
	return p.Type != "object" && p.Type != "array"
}

func (p Property) Sub(name string) (Property, bool) {
	for _, s := range p.Properties {
		if s.Name == name {
			return s, true
		}
	}
	return Property{}, false
}

func propertyNames(props []Property) []string {
	out := make([]string, len(props))
	for i, p := range props {
		out[i] = p.Name
	}
	return out
}

// orderedParameters decodes a parameters object keeping key order.
func orderedParameters(raw json.RawMessage) []Parameter {
	var out []Parameter
	eachOrdered(raw, func(k string, v json.RawMessage) {
		var p Parameter
		if json.Unmarshal(v, &p) == nil {
			p.Name = k
			out = append(out, p)
		}
	})
	return out
}

func orderedOrder(raw json.RawMessage) []sqlf.Order {
	var out []sqlf.Order
	eachOrdered(raw, func(k string, v json.RawMessage) {
		var dir string
		_ = json.Unmarshal(v, &dir)
		out = append(out, sqlf.Order{Column: k, Desc: dir == "desc"})
	})
	return out
}

func eachOrdered(raw json.RawMessage, fn func(string, json.RawMessage)) {
	if len(raw) == 0 {
		return
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	if t, err := dec.Token(); err != nil || t != json.Delim('{') {
		return
	}
	for dec.More() {
		k, err := dec.Token()
		if err != nil {
			return
		}
		var v json.RawMessage
		if err := dec.Decode(&v); err != nil {
			return
		}
		fn(k.(string), v)
	}
}

func (d *Definition) Parameter(name string) Parameter {
	for _, p := range d.Parameters {
		if p.Name == name {
			return p
		}
	}
	return Parameter{}
}

// Output is the property list rendered by the operation (or the resource).
func (d *Definition) Output() []Property {
	if d.OutputNames == nil {
		return d.Properties
	}
	out := make([]Property, 0, len(d.OutputNames))
	for _, n := range d.OutputNames {
		if p, ok := d.Property(n); ok {
			out = append(out, p)
		}
	}
	return out
}

// HiddenIdentifier is the identifier when Output() omits it: items still
// carry its value, so their identity never depends on the rendering.
// Encoders render Output() only.
func (d *Definition) HiddenIdentifier() (Property, bool) {
	id := d.Identifier()
	if id.Name == "" {
		return Property{}, false
	}
	for _, p := range d.Output() {
		if p.Name == id.Name {
			return Property{}, false
		}
	}
	return id, true
}

// IsItem reports an operation on one item: its path holds one {<identifier>}
// placeholder, at the end (/authors/{id}) or before literal segments
// (/purchases/{id}/refund).
func (op Operation) IsItem() bool {
	return strings.Contains(op.Path, "{")
}

// Placeholder is the name inside the braces of an item operation path.
func (op Operation) Placeholder() string {
	_, rest, _ := strings.Cut(op.Path, "{")
	name, _, _ := strings.Cut(rest, "}")
	return name
}

// Prefix is the path before the /{id} placeholder.
func (op Operation) Prefix() string {
	if i := strings.Index(op.Path, "/{"); i >= 0 {
		return op.Path[:i]
	}
	return op.Path
}

// Suffix is the path after the {id} placeholder ("" or "/refund").
func (op Operation) Suffix() string {
	if _, after, ok := strings.Cut(op.Path, "}"); ok {
		return after
	}
	return ""
}

// Resolve is the path of the operation for the identifier id.
func (op Operation) Resolve(id string) string {
	if !op.IsItem() {
		return op.Path
	}
	return op.Prefix() + "/" + id + op.Suffix()
}

// IgnoresBody reports a definition (as seen by an operation) declaring an
// empty input: its requests carry no body, and one sent is not read.
func (d *Definition) IgnoresBody() bool {
	return d.Input != nil && len(d.Input) == 0
}

// resolveProperty resolves a property path (title, author.name) to its
// property and to the column of the query result holding it.
func (d *Definition) resolveProperty(path string) (Property, string, string) {
	head, sub, nested := strings.Cut(path, ".")
	p, ok := d.Property(head)
	if !ok {
		hint := "Properties: " + strings.Join(propertyNames(d.Properties), ", ") + "."
		if s := apierr.Suggest(head, propertyNames(d.Properties)); s != "" {
			hint = fmt.Sprintf("Did you mean %q? ", s) + hint
		}
		return Property{}, "", hint
	}
	if !nested {
		return p, p.Name, ""
	}
	if p.Type != "object" {
		return Property{}, "", fmt.Sprintf("%q is a %s property; only object properties have nested properties (author.name).", head, p.Type)
	}
	s, ok := p.Sub(sub)
	if !ok {
		return Property{}, "", fmt.Sprintf("Nested properties of %s: %s.", head, strings.Join(propertyNames(p.Properties), ", "))
	}
	return s, head + "_" + s.Name, ""
}

var filterTypes = map[string][]string{
	"exact":    {"string", "integer", "number", "boolean", "date", "datetime"},
	"partial":  {"string"},
	"ipartial": {"string"},
	"range":    {"integer", "number", "date", "datetime"},
	"date":     {"date", "datetime"},
	"boolean":  {"boolean"},
}

// resolveParameters fills defaults (type, column) and validates parameters
// against the properties; at is the JSON pointer of the parameters object.
func resolveParameters(d *Definition, params []Parameter, at string, loadHandler func(name, ptr string) script.Module) apierr.List {
	var errs apierr.List
	add := func(code apierr.Code, ptr, msg, hint string) {
		errs = append(errs, &apierr.Error{Code: code, Resource: d.Name, Pointer: ptr, Message: msg, Hint: hint})
	}
	for i := range params {
		p := &params[i]
		ptr := at + "/" + escapePointer(p.Name)
		if p.Filter != "" && p.In == "header" {
			add(apierr.InvalidValue, ptr+"/filter", fmt.Sprintf("parameter %q is a header but has filter %q", p.Name, p.Filter), "Built-in filters read the query string: set \"in\": \"query\", or use a handler for a header.")
		}
		switch p.Filter {
		case "":
		case "order":
			if len(p.Properties) == 0 {
				add(apierr.MissingField, ptr+"/properties", fmt.Sprintf("order parameter %q has no \"properties\"", p.Name), "List the properties clients may sort on, e.g. \"properties\": [\"title\", \"priceCents\"].")
			}
			p.sortColumns = map[string]string{}
			for j, name := range p.Properties {
				prop, col, hint := d.resolveProperty(name)
				if col == "" {
					add(apierr.InvalidValue, fmt.Sprintf("%s/properties/%d", ptr, j), fmt.Sprintf("order parameter %q lists unknown property %q", p.Name, name), hint)
					continue
				}
				if !prop.Scalar() {
					add(apierr.InvalidValue, fmt.Sprintf("%s/properties/%d", ptr, j), fmt.Sprintf("cannot sort on %s property %q", prop.Type, name), "Sort on scalar properties (string, integer, number, boolean, date, datetime), or on a nested one: author.name.")
					continue
				}
				p.sortColumns[name] = col
			}
		default:
			target := p.Property
			ptrProp := ptr + "/property"
			if target == "" {
				target, ptrProp = p.Name, ptr
			}
			prop, col, hint := d.resolveProperty(target)
			if col == "" {
				add(apierr.InvalidValue, ptrProp, fmt.Sprintf("parameter %q filters unknown property %q", p.Name, target), hint+" Set \"property\" when the parameter is named differently.")
				continue
			}
			if !slices.Contains(filterTypes[p.Filter], prop.Type) {
				add(apierr.InvalidValue, ptr+"/filter", fmt.Sprintf("filter %q cannot apply to %s property %q", p.Filter, prop.Type, target), fmt.Sprintf("%s works on: %s.", p.Filter, strings.Join(filterTypes[p.Filter], ", ")))
				continue
			}
			p.column = col
			if p.Type == "" {
				p.Type = prop.Type
			}
		}
		if p.Pagination != "" {
			if p.Type != "" && p.Type != "integer" {
				add(apierr.InvalidValue, ptr+"/type", fmt.Sprintf("pagination parameter %q has type %q", p.Name, p.Type), "Pagination parameters are integers: remove \"type\" or set it to \"integer\".")
			}
			p.Type = "integer"
		}
		if p.Type == "" {
			p.Type = "string"
		}
		if p.Handler != "" {
			p.HandlerMod = loadHandler(p.Handler, ptr+"/handler")
		}
	}
	return errs
}

func escapePointer(s string) string {
	return strings.NewReplacer("~", "~0", "/", "~1").Replace(s)
}

// mergeParameters returns base with extra appended, extra overriding base
// parameters of the same name.
func mergeParameters(base, extra []Parameter) []Parameter {
	if len(extra) == 0 {
		return base
	}
	out := make([]Parameter, 0, len(base)+len(extra))
	for _, b := range base {
		if !slices.ContainsFunc(extra, func(e Parameter) bool { return e.Name == b.Name }) {
			out = append(out, b)
		}
	}
	return append(out, extra...)
}

func validateOrder(d *Definition, order []sqlf.Order, at string) apierr.List {
	var errs apierr.List
	for _, o := range order {
		prop, _, hint := d.resolveProperty(o.Column)
		if prop.Name == "" || strings.Contains(o.Column, ".") || !prop.Scalar() {
			if hint == "" {
				hint = "Order on a scalar property of the resource."
			}
			errs = append(errs, &apierr.Error{Code: apierr.InvalidValue, Resource: d.Name, Pointer: at + "/" + escapePointer(o.Column), Message: fmt.Sprintf("default order names %q, which is not a scalar property", o.Column), Hint: hint})
		}
	}
	return errs
}

func validateRelations(d *Definition) apierr.List {
	var errs apierr.List
	names := make([]string, 0, len(d.Relations))
	for n := range d.Relations {
		names = append(names, n)
	}
	sort.Strings(names)
	for _, n := range names {
		r := d.Relations[n]
		ptr := "/relations/" + n
		switch {
		case r.Many && r.ForeignKey == "":
			errs = append(errs, &apierr.Error{Code: apierr.MissingField, Resource: d.Name, Pointer: ptr, Message: fmt.Sprintf("to-many relation %q has no \"foreignKey\"", n), Hint: fmt.Sprintf("Set \"foreignKey\" to the column of %s's table that holds the %s identifier, e.g. \"author_id\".", r.Resource, d.Name)})
		case !r.Many && r.Column == "":
			errs = append(errs, &apierr.Error{Code: apierr.MissingField, Resource: d.Name, Pointer: ptr, Message: fmt.Sprintf("to-one relation %q has no \"column\"", n), Hint: fmt.Sprintf("Set \"column\" to the column of this table holding the %s identifier, e.g. \"author_id\"; for a to-many relation set \"foreignKey\" and \"many\": true.", r.Resource)})
		}
	}
	return errs
}

// crossCheck validates what needs every definition: related resources.
func crossCheck(defs []*Definition) apierr.List {
	var errs apierr.List
	byName := map[string]*Definition{}
	var all []string
	for _, d := range defs {
		byName[d.Name] = d
		all = append(all, d.Name)
	}
	for _, d := range defs {
		names := make([]string, 0, len(d.Relations))
		for n := range d.Relations {
			names = append(names, n)
		}
		sort.Strings(names)
		for _, n := range names {
			r := d.Relations[n]
			ptr := "/relations/" + n + "/resource"
			other, ok := byName[r.Resource]
			if !ok {
				hint := "Resources: " + strings.Join(all, ", ") + "."
				if s := apierr.Suggest(r.Resource, all); s != "" {
					hint = fmt.Sprintf("Did you mean %q? ", s) + hint
				}
				errs = append(errs, &apierr.Error{Code: apierr.InvalidValue, Resource: d.Name, Pointer: ptr, Message: fmt.Sprintf("relation %q targets unknown resource %q", n, r.Resource), Hint: hint})
				continue
			}
			if other.Table == "" {
				errs = append(errs, &apierr.Error{Code: apierr.InvalidValue, Resource: d.Name, Pointer: ptr, Message: fmt.Sprintf("relation %q targets %s, which declares no table", n, r.Resource), Hint: fmt.Sprintf("Add \"table\" to %s (it may keep its \"query\"): helpers need the table and its columns.", other.File)})
			}
		}
	}
	return errs
}
