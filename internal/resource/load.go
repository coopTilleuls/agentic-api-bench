package resource

import (
	"bytes"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strings"

	"github.com/santhosh-tekuri/jsonschema/v6"
	"github.com/santhosh-tekuri/jsonschema/v6/kind"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

//go:embed schema.json
var SchemaJSON []byte

const DefaultItemsPerPage = 30

var ReservedPaths = []string{"/mcp", "/openapi.json", "/contexts", "/docs.jsonld", "/errors"}

var Types = []string{"string", "integer", "number", "boolean", "date", "datetime", "object", "array"}

type Property struct {
	Name        string `json:"-"`
	Type        string `json:"type"`
	Identifier  bool   `json:"identifier,omitempty"`
	Filterable  bool   `json:"filterable,omitempty"`
	Required    bool   `json:"required,omitempty"`
	ReadOnly    bool   `json:"readOnly,omitempty"`
	Description string `json:"description,omitempty"`
	Column      string `json:"column,omitempty"`
	Computed    bool   `json:"computed,omitempty"`
	// Properties of an object property, Items of an array one.
	Properties []Property `json:"-"`
	Items      *Property  `json:"items,omitempty"`
	// Input constraints.
	Nullable  *bool    `json:"nullable,omitempty"`
	Min       *float64 `json:"min,omitempty"`
	Max       *float64 `json:"max,omitempty"`
	MinLength *int     `json:"minLength,omitempty"`
	MaxLength *int     `json:"maxLength,omitempty"`
	MinItems  *int     `json:"minItems,omitempty"`
	MaxItems  *int     `json:"maxItems,omitempty"`
	Enum      []any    `json:"enum,omitempty"`
}

type Allow struct {
	Fetch []string `json:"fetch,omitempty"`
	Env   []string `json:"env,omitempty"`
}

type Definition struct {
	Name         string
	Path         string
	Description  string
	Table        string
	Query        string
	Command      string
	ReadOnly     bool
	Allow        Allow
	Formats      []string
	Ops          []Operation
	Input        []Property
	ItemsPerPage int
	Properties   []Property
	File         string
	QueryMod     script.Module
	CommandMod   script.Module

	Relations          map[string]Relation
	Parameters         []Parameter
	Order              []sqlf.Order
	Envelope           map[string]string
	Errors             ErrorShape
	IgnoreUnknownInput bool
	ErrorDetails       *bool
	// OutputNames is set by ForOperation when the operation declares output.
	OutputNames []string
	// Current is the operation this view was made for (ForOperation).
	Current *Operation
}

// Operation is a dedicated endpoint declared in "operations".
type Operation struct {
	Method      string        `json:"method"`
	Path        string        `json:"path"`
	Format      string        `json:"format,omitempty"`
	Query       string        `json:"query,omitempty"`
	Command     string        `json:"command,omitempty"`
	Description string        `json:"description,omitempty"`
	Output      []string      `json:"output,omitempty"`
	Input       []Property    `json:"-"`
	Parameters  []Parameter   `json:"-"`
	Order       []sqlf.Order  `json:"-"`
	QueryMod    script.Module `json:"-"`
	CommandMod  script.Module `json:"-"`
	// Methods lists the methods of an operation declared with a method
	// array; load expands it into one Operation per method, all Multi.
	Methods []string `json:"-"`
	Multi   bool     `json:"-"`
	// Index is the position in the declared "operations" array.
	Index int `json:"-"`
}

// UnmarshalJSON accepts "method" as one method or an array of methods.
func (op *Operation) UnmarshalJSON(b []byte) error {
	type plain Operation
	aux := struct {
		Method json.RawMessage `json:"method"`
		*plain
	}{plain: (*plain)(op)}
	if err := json.Unmarshal(b, &aux); err != nil {
		return err
	}
	if len(aux.Method) > 0 && aux.Method[0] == '[' {
		return json.Unmarshal(aux.Method, &op.Methods)
	}
	return json.Unmarshal(aux.Method, &op.Method)
}

// expandMethods replaces every operation declared with a method array by one
// operation per method, in declaration order.
func (d *Definition) expandMethods() {
	var out []Operation
	for _, op := range d.Ops {
		if len(op.Methods) == 0 {
			out = append(out, op)
			continue
		}
		op.Multi = true
		for _, m := range op.Methods {
			c := op
			c.Method = m
			out = append(out, c)
		}
	}
	d.Ops = out
}

// Reads tells whether the operation runs the read flow: a GET, or a multi
// method operation whose handler is a query. A multi method operation is
// chosen by its handler, not by the method.
func (op Operation) Reads() bool {
	if op.Multi {
		return op.Query != ""
	}
	return op.Method == "GET"
}

var AllFormats = []string{"jsonld", "json", "csv"}

// ForOperation returns the definition as seen by op: the operation's own
// query and command replace the definition's.
func (d *Definition) ForOperation(op Operation) *Definition {
	c := *d
	if op.Query != "" {
		c.Table, c.Query, c.QueryMod = "", op.Query, op.QueryMod
	}
	if op.Command != "" {
		c.Command, c.CommandMod = op.Command, op.CommandMod
	}
	if op.Input != nil {
		c.Input = op.Input
	}
	if op.Output != nil {
		c.OutputNames = op.Output
	}
	if op.Order != nil {
		c.Order = op.Order
	}
	c.Parameters = mergeParameters(d.Parameters, op.Parameters)
	c.Current = &op
	return &c
}

// InputProps is the input DTO: the declared input, or the properties that
// are not readOnly.
func (d *Definition) InputProps() []Property {
	if d.Input != nil {
		return d.Input
	}
	return d.Writable()
}

func (d *Definition) InputProp(name string) (Property, bool) {
	for _, p := range d.InputProps() {
		if p.Name == name {
			return p, true
		}
	}
	return Property{}, false
}

var CommandOperations = []string{"create", "update", "delete"}

// CommandExport returns the function implementing op in mod. `delete` is a
// reserved word in JavaScript (`export function delete` does not parse), so
// `remove` is accepted for it.
func CommandExport(mod script.Module, op string) string {
	exports := mod.Exports()
	if slices.Contains(exports, op) {
		return op
	}
	if op == "delete" && slices.Contains(exports, "remove") {
		return "remove"
	}
	return ""
}

// Operations returns the write operations the resource accepts.
func (d *Definition) Operations() []string {
	switch {
	case d.ReadOnly || len(d.Ops) > 0:
		return nil
	case d.CommandMod != nil:
		var ops []string
		for _, op := range CommandOperations {
			if CommandExport(d.CommandMod, op) != "" {
				ops = append(ops, op)
			}
		}
		return ops
	case d.Table != "":
		return CommandOperations
	}
	return nil
}

func (d *Definition) Writable() []Property {
	var out []Property
	for _, p := range d.Properties {
		if !p.ReadOnly {
			out = append(out, p)
		}
	}
	return out
}

func (d *Definition) Identifier() Property {
	for _, p := range d.Properties {
		if p.Identifier {
			return p
		}
	}
	return Property{}
}

func (d *Definition) Property(name string) (Property, bool) {
	for _, p := range d.Properties {
		if p.Name == name {
			return p, true
		}
	}
	return Property{}, false
}

func (d *Definition) Columns() []string {
	cols := make([]string, len(d.Properties))
	for i, p := range d.Properties {
		cols[i] = p.Name
	}
	return cols
}

func (d *Definition) Filterable() []Property {
	var out []Property
	for _, p := range d.Properties {
		if p.Filterable {
			out = append(out, p)
		}
	}
	return out
}

type Set struct {
	Dir    string
	Defs   []*Definition
	Broken []Broken
}

// Broken is a definition that failed validation. Its paths are still known
// (when they could be read) so requests to them can answer its errors.
type Broken struct {
	File   string
	Name   string
	Paths  []string
	Errors apierr.List
}

func paths(d *Definition) []string {
	var out []string
	if d.Path != "" {
		out = append(out, d.Path)
	}
	for _, op := range d.Ops {
		out = append(out, op.Path)
	}
	return out
}

// Demote moves the definitions named by errs to Broken. An error naming no
// definition (the database is down) demotes them all.
func (s *Set) Demote(errs apierr.List) {
	if len(errs) == 0 {
		return
	}
	byName := map[string]apierr.List{}
	var global apierr.List
	for _, e := range errs {
		if e.Resource == "" {
			global = append(global, e)
		} else {
			byName[e.Resource] = append(byName[e.Resource], e)
		}
	}
	var keep []*Definition
	for _, d := range s.Defs {
		mine := append(byName[d.Name], global...)
		if len(mine) == 0 {
			keep = append(keep, d)
			continue
		}
		s.Broken = append(s.Broken, Broken{File: d.File, Name: d.Name, Paths: paths(d), Errors: mine})
	}
	s.Defs = keep
}

func (s *Set) ByName(name string) *Definition {
	for _, d := range s.Defs {
		if d.Name == name {
			return d
		}
	}
	return nil
}

var compiledSchema = func() *jsonschema.Schema {
	doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(SchemaJSON))
	if err != nil {
		panic(err)
	}
	c := jsonschema.NewCompiler()
	if err := c.AddResource("resource.schema.json", doc); err != nil {
		panic(err)
	}
	return c.MustCompile("resource.schema.json")
}()

var schemaDoc = func() map[string]any {
	var m map[string]any
	if err := json.Unmarshal(SchemaJSON, &m); err != nil {
		panic(err)
	}
	return m
}()

func isQueryFile(name string) bool {
	for _, suffix := range []string{".query.ts", ".query.js", ".command.ts", ".command.js", ".handler.ts", ".handler.js", ".star", ".py"} {
		if strings.HasSuffix(name, suffix) {
			return true
		}
	}
	return false
}

func isDefinitionFile(name string) bool {
	if strings.HasPrefix(name, ".") || strings.HasPrefix(name, "_") || isQueryFile(name) || strings.HasSuffix(name, ".d.ts") {
		return false
	}
	switch name {
	case "package.json", "package-lock.json", "tsconfig.json", "jsconfig.json":
		return false
	}
	return strings.HasSuffix(name, ".json") || strings.HasSuffix(name, ".ts")
}

func Load(dir string, engine script.Engine) (*Set, apierr.List, apierr.List) {
	info, err := os.Stat(dir)
	if err != nil || !info.IsDir() {
		abs, _ := filepath.Abs(dir)
		return &Set{Dir: dir}, apierr.List{{Code: apierr.DirNotFound, Message: fmt.Sprintf("definitions directory %q does not exist", abs), Hint: "Create the directory and put one <Name>.json or <Name>.ts file per resource in it, or run `caddy resource init`."}}, nil
	}
	entries, err := os.ReadDir(dir)
	if err != nil {
		return &Set{Dir: dir}, apierr.List{{Code: apierr.DirNotFound, Message: err.Error(), Hint: "Make the directory readable."}}, nil
	}
	files := map[string][]byte{}
	for _, e := range entries {
		if e.IsDir() {
			continue
		}
		b, err := os.ReadFile(filepath.Join(dir, e.Name()))
		if err != nil {
			return &Set{Dir: dir}, apierr.List{{Code: apierr.DirNotFound, Message: err.Error(), Hint: "Make the files readable."}}, nil
		}
		files[e.Name()] = b
	}
	set, errs, warns := loadFiles(files, nil, engine)
	set.Dir = dir
	return set, errs, warns
}

// LoadInline validates one definition given as source; resolve supplies the
// script files it references.
func LoadInline(filename string, src []byte, resolve func(name string) ([]byte, bool), engine script.Engine) (*Set, apierr.List, apierr.List) {
	set, errs, warns := loadFiles(map[string][]byte{filename: src}, resolve, engine)
	var keep apierr.List
	for _, w := range warns {
		if w.Code != apierr.UnusedQueryFile {
			keep = append(keep, w)
		}
	}
	return set, errs, keep
}

func loadFiles(files map[string][]byte, resolve func(string) ([]byte, bool), engine script.Engine) (*Set, apierr.List, apierr.List) {
	lookup := func(name string) ([]byte, bool) {
		if b, ok := files[name]; ok {
			return b, true
		}
		if resolve != nil {
			return resolve(name)
		}
		return nil, false
	}
	names := make([]string, 0, len(files))
	for n := range files {
		names = append(names, n)
	}
	sort.Strings(names)

	set := &Set{}
	var errs, warns apierr.List
	usedQueries := map[string]bool{}
	for _, name := range names {
		if !isDefinitionFile(name) {
			continue
		}
		def, defErrs := loadDefinition(name, files, lookup, engine)
		errs = append(errs, defErrs...)
		if len(defErrs) > 0 {
			b := Broken{File: name, Name: name, Errors: defErrs}
			if def != nil {
				b.Name, b.Paths = def.Name, paths(def)
			} else {
				b.Name, b.Paths = sketch(files[name], name)
			}
			set.Broken = append(set.Broken, b)
		}
		if def != nil {
			warns = append(warns, headerPrefixWarnings(def)...)
			usedQueries[def.Query] = true
			usedQueries[def.Command] = true
			for _, op := range def.Ops {
				usedQueries[op.Query] = true
				usedQueries[op.Command] = true
				for _, p := range op.Parameters {
					usedQueries[p.Handler] = true
				}
			}
			for _, p := range def.Parameters {
				usedQueries[p.Handler] = true
			}
			if len(defErrs) == 0 {
				set.Defs = append(set.Defs, def)
			}
		}
	}

	var crossErrs apierr.List
	byName, byPath := map[string]string{}, map[string]string{}
	for _, d := range set.Defs {
		if other, ok := byName[d.Name]; ok {
			crossErrs = append(crossErrs, &apierr.Error{Code: apierr.DuplicateName, Resource: d.Name, Pointer: "/name", Message: fmt.Sprintf("%s and %s both define a resource named %q", other, d.File, d.Name), Hint: "Resource names must be unique; rename one of them."})
		}
		if other, ok := byPath[d.Path]; ok && d.Path != "" {
			crossErrs = append(crossErrs, &apierr.Error{Code: apierr.DuplicatePath, Resource: d.Name, Pointer: "/path", Message: fmt.Sprintf("%s and %s both use path %q", other, d.File, d.Path), Hint: "Paths must be unique; change one of them."})
		}
		byName[d.Name], byPath[d.Path] = d.File, d.File
	}
	routes := map[string]string{}
	for _, d := range set.Defs {
		for _, op := range d.Ops {
			key := op.Method + " " + op.Path
			if other, ok := routes[key]; ok && other != d.File {
				crossErrs = append(crossErrs, &apierr.Error{Code: apierr.DuplicatePath, Resource: d.Name, Pointer: fmt.Sprintf("/operations/%d/path", op.Index), Message: fmt.Sprintf("%s and %s both declare %s", other, d.File, key), Hint: "Each method + path pair must be unique, also across operations declared with a method array.", Topic: "operations"})
			}
			routes[key] = d.File
			if other, ok := byPath[op.Path]; ok && other != d.File {
				crossErrs = append(crossErrs, &apierr.Error{Code: apierr.DuplicatePath, Resource: d.Name, Pointer: fmt.Sprintf("/operations/%d/path", op.Index), Message: fmt.Sprintf("operation path %q is already the path of the resource in %s", op.Path, other), Hint: "Choose another path for the operation."})
			}
		}
	}

	crossErrs = append(crossErrs, crossCheck(set.Defs)...)
	errs = append(errs, crossErrs...)
	set.Demote(crossErrs)

	hasDefinitionFile := false
	for _, name := range names {
		if isQueryFile(name) && !usedQueries[name] {
			warns = append(warns, &apierr.Error{Code: apierr.UnusedQueryFile, Resource: name, Message: fmt.Sprintf("%s is not referenced by any definition", name), Hint: fmt.Sprintf("Reference it from a definition with \"query\": %q, or delete it.", name)})
		}
		if isDefinitionFile(name) {
			hasDefinitionFile = true
		}
	}
	if !hasDefinitionFile {
		warns = append(warns, &apierr.Error{Code: apierr.NoResources, Message: "no definition file found", Hint: "Add one <Name>.json or <Name>.ts file per resource to the definitions directory. Run `caddy resource guide` for the format, or `caddy resource init` for an example."})
	}
	return set, errs, warns
}

// sketch reads the name and paths of a definition that failed before it
// could be decoded, so its endpoints can still report the errors.
func sketch(src []byte, file string) (string, []string) {
	var raw struct {
		Name       string `json:"name"`
		Path       string `json:"path"`
		Operations []struct {
			Path string `json:"path"`
		} `json:"operations"`
	}
	if json.Unmarshal(src, &raw) != nil {
		return file, nil
	}
	name := raw.Name
	if name == "" {
		name = file
	}
	var out []string
	if strings.HasPrefix(raw.Path, "/") {
		out = append(out, raw.Path)
	}
	for _, op := range raw.Operations {
		if strings.HasPrefix(op.Path, "/") {
			out = append(out, op.Path)
		}
	}
	return name, out
}

func loadDefinition(file string, files map[string][]byte, lookup func(string) ([]byte, bool), engine script.Engine) (*Definition, apierr.List) {
	src := files[file]
	if strings.HasSuffix(file, ".ts") {
		out, err := engine.EvalDefinition(file, src)
		if err != nil {
			e, ok := apierr.As(err)
			if !ok {
				e = &apierr.Error{Code: apierr.ScriptError, Message: err.Error()}
			}
			e.Resource = file
			if e.Code == apierr.InvalidExport {
				e.Hint = "A definition file must `export default defineResource({ ... })`. A query function belongs in a file named <name>.query.ts, referenced from the definition with \"query\"."
			}
			return nil, apierr.List{e}
		}
		src = out
	}

	doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(src))
	if err != nil {
		return nil, apierr.List{jsonError(file, src, err)}
	}
	resourceName := file
	if m, ok := doc.(map[string]any); ok {
		if n, ok := m["name"].(string); ok && n != "" {
			resourceName = n
		}
	}

	if errs := propertyNameErrors(resourceName, doc); len(errs) > 0 {
		return nil, errs
	}
	if err := compiledSchema.Validate(doc); err != nil {
		var verr *jsonschema.ValidationError
		if errors.As(err, &verr) {
			return nil, schemaErrors(resourceName, verr)
		}
		return nil, apierr.List{{Code: apierr.InvalidValue, Resource: resourceName, Message: err.Error()}}
	}

	var raw struct {
		Name         string                     `json:"name"`
		Path         string                     `json:"path"`
		Description  string                     `json:"description"`
		Table        string                     `json:"table"`
		Query        string                     `json:"query"`
		Command      string                     `json:"command"`
		ReadOnly     bool                       `json:"readOnly"`
		Allow        Allow                      `json:"allow"`
		Formats      []string                   `json:"formats"`
		Ops          []Operation                `json:"operations"`
		ItemsPerPage int                        `json:"itemsPerPage"`
		Properties   map[string]json.RawMessage `json:"properties"`
		Relations    map[string]Relation        `json:"relations"`
		Envelope     map[string]string          `json:"envelope"`
		Errors       ErrorShape                 `json:"errors"`
		UnknownInput string                     `json:"unknownInput"`
		ErrorDetails *bool                      `json:"errorDetails"`
	}
	if err := json.Unmarshal(src, &raw); err != nil {
		return nil, apierr.List{jsonError(file, src, err)}
	}
	def := &Definition{Name: raw.Name, Path: raw.Path, Description: raw.Description, Table: raw.Table, Query: raw.Query, Command: raw.Command, ReadOnly: raw.ReadOnly, Allow: raw.Allow, Formats: raw.Formats, Ops: raw.Ops, ItemsPerPage: raw.ItemsPerPage, File: file,
		Relations: raw.Relations, Envelope: raw.Envelope, Errors: raw.Errors, IgnoreUnknownInput: raw.UnknownInput == "ignore", ErrorDetails: raw.ErrorDetails}
	if def.ItemsPerPage == 0 {
		def.ItemsPerPage = DefaultItemsPerPage
	}
	var top map[string]json.RawMessage
	_ = json.Unmarshal(src, &top)
	def.Properties = orderedProperties(top["properties"])
	def.Input = orderedProperties(top["input"])
	def.Parameters = orderedParameters(top["parameters"])
	def.Order = orderedOrder(top["order"])
	var rawOps []map[string]json.RawMessage
	_ = json.Unmarshal(top["operations"], &rawOps)
	for i := range def.Ops {
		if i < len(rawOps) {
			def.Ops[i].Input = orderedProperties(rawOps[i]["input"])
			def.Ops[i].Parameters = orderedParameters(rawOps[i]["parameters"])
			def.Ops[i].Order = orderedOrder(rawOps[i]["order"])
		}
		def.Ops[i].Index = i
	}

	var errs apierr.List
	add := func(code apierr.Code, pointer, msg, hint string) {
		errs = append(errs, &apierr.Error{Code: code, Resource: def.Name, Pointer: pointer, Message: msg, Hint: hint})
	}

	var ids []string
	for _, p := range def.Properties {
		if p.Identifier {
			ids = append(ids, p.Name)
		}
	}
	switch len(ids) {
	case 0:
		if len(def.Ops) > 0 {
			break
		}
		add(apierr.MissingIdentifier, "/properties", "no property is marked as identifier", "Add \"identifier\": true to exactly one property, usually \"id\": {\"type\": \"integer\", \"identifier\": true}.")
	case 1:
		if p, _ := def.Property(ids[0]); p.Type != "integer" && p.Type != "string" {
			add(apierr.InvalidValue, "/properties/"+p.Name+"/type", fmt.Sprintf("identifier %q has type %q", p.Name, p.Type), "The identifier must have type integer or string.")
		}
	default:
		add(apierr.MultipleIdentifiers, "/properties", fmt.Sprintf("properties %s are all marked as identifier", strings.Join(ids, ", ")), "Keep \"identifier\": true on exactly one property; composite identifiers are not supported.")
	}

	addOp := func(code apierr.Code, pointer, msg, hint string) {
		errs = append(errs, &apierr.Error{Code: code, Resource: def.Name, Pointer: pointer, Message: msg, Hint: hint, Topic: "operations"})
	}

	checkReserved := func(path, ptr string) {
		for _, reserved := range ReservedPaths {
			if path == reserved || strings.HasPrefix(path, reserved+"/") {
				add(apierr.InvalidValue, ptr, fmt.Sprintf("path %q is reserved", path), fmt.Sprintf("These paths are used by the server: %s. Choose another path.", strings.Join(ReservedPaths, ", ")))
			}
		}
	}
	checkReserved(def.Path, "/path")
	if def.Path == "" && len(def.Ops) == 0 {
		add(apierr.MissingField, "/path", "required key \"path\" is missing", "Set the collection path, e.g. \"path\": \"/books\". Only definitions declaring \"operations\" may omit it.")
	}

	loadQuery := func(name, ptr string) script.Module {
		mod, e := loadScript(def, name, ptr, file, files, lookup, engine, apierr.QueryFileNotFound, queryExample(name))
		if e != nil {
			errs = append(errs, e)
			return nil
		}
		if !slices.Contains(mod.Exports(), "default") {
			add(apierr.InvalidExport, ptr, name+" does not define the query function", "Write "+queryExample(name)+".")
			return nil
		}
		return mod
	}
	loadCommand := func(name, ptr string) script.Module {
		mod, e := loadScript(def, name, ptr, file, files, lookup, engine, apierr.CommandFileNotFound, commandExample(name))
		if e != nil {
			errs = append(errs, e)
			return nil
		}
		var ops, unknown []string
		for _, ex := range mod.Exports() {
			if slices.Contains(CommandOperations, ex) || ex == "remove" {
				ops = append(ops, ex)
			} else {
				unknown = append(unknown, ex)
			}
		}
		if len(ops) == 0 {
			msg := name + " defines none of create, update, remove"
			if len(unknown) > 0 {
				msg += " (it defines: " + strings.Join(unknown, ", ") + ")"
			}
			add(apierr.InvalidExport, ptr, msg, "Define "+commandExample(name)+" (remove handles DELETE).")
			return nil
		}
		return mod
	}

	loadHandler := func(name, ptr string) script.Module {
		mod, e := loadScript(def, name, ptr, file, files, lookup, engine, apierr.QueryFileNotFound, handlerExample(name))
		if e != nil {
			errs = append(errs, e)
			return nil
		}
		if !slices.Contains(mod.Exports(), "default") && !slices.Contains(mod.Exports(), "handler") {
			add(apierr.InvalidExport, ptr, name+" does not define the handler function", "Write "+handlerExample(name)+".")
			return nil
		}
		return mod
	}
	errs = append(errs, validateRelations(def)...)
	errs = append(errs, validateColumns(def)...)
	errs = append(errs, resolveParameters(def, def.Parameters, "/parameters", loadHandler)...)
	errs = append(errs, validateOrder(def, def.Order, "/order")...)

	if len(def.Ops) > 0 {
		if def.Query != "" {
			def.QueryMod = loadQuery(def.Query, "/query")
		}
		if def.Command != "" {
			def.CommandMod = loadCommand(def.Command, "/command")
		}
		seen := map[string]bool{}
		for i := range def.Ops {
			op := &def.Ops[i]
			ptr := fmt.Sprintf("/operations/%d", i)
			checkReserved(op.Path, ptr+"/path")
			for _, m := range op.methodList() {
				key := m + " " + op.Path
				if seen[key] {
					addOp(apierr.DuplicatePath, ptr, fmt.Sprintf("operation %s is declared twice", key), "Each method + path pair must be unique, also across operations declared with a method array.")
				}
				seen[key] = true
			}
			if op.Query != "" {
				op.QueryMod = loadQuery(op.Query, ptr+"/query")
			}
			if op.Command != "" {
				op.CommandMod = loadCommand(op.Command, ptr+"/command")
			}
			errs = append(errs, resolveParameters(def, op.Parameters, ptr+"/parameters", loadHandler)...)
			errs = append(errs, validateOrder(def, op.Order, ptr+"/order")...)
			if op.IsItem() {
				id := def.Identifier().Name
				if placeholder := op.Placeholder(); placeholder != id {
					add(apierr.InvalidValue, ptr+"/path", fmt.Sprintf("operation path %q has the placeholder {%s}, which is not the identifier", op.Path, placeholder), fmt.Sprintf("An item operation holds the identifier placeholder once: %s.", op.Prefix()+"/{"+id+"}"+op.Suffix()))
				}
			}
			for j, name := range op.Output {
				if _, ok := def.Property(name); !ok {
					hint := "Properties: " + strings.Join(propertyNames(def.Properties), ", ") + ". Declare every output property in \"properties\"; output picks among them."
					if s := apierr.Suggest(name, propertyNames(def.Properties)); s != "" {
						hint = fmt.Sprintf("Did you mean %q? ", s) + hint
					}
					add(apierr.InvalidValue, fmt.Sprintf("%s/output/%d", ptr, j), fmt.Sprintf("operation output names unknown property %q", name), hint)
				}
			}
			if op.Methods != nil {
				errs = append(errs, validateMultiMethod(def, *op, ptr)...)
			} else if op.Method == "GET" {
				if op.Query == "" && def.Query == "" && def.Table == "" {
					add(apierr.MissingSource, ptr, fmt.Sprintf("operation GET %s has nothing to read", op.Path), "Give the operation a \"query\" script, or set \"query\" or \"table\" on the definition.")
				}
				if op.Command != "" {
					add(apierr.InvalidValue, ptr+"/command", "a GET operation cannot have a command", "Use \"query\" for GET; commands handle POST, PUT, PATCH and DELETE.")
				}
			} else if op.Command == "" && def.Command == "" && (def.Table == "" || !op.IsItem() || op.Method == "POST") {
				add(apierr.MissingSource, ptr, fmt.Sprintf("operation %s %s has no command", op.Method, op.Path), "Give the operation a \"command\" script defining "+commandFunction(op.Method)+".")
			}
		}
		def.expandMethods()
		return def, errs
	}

	switch {
	case def.Table == "" && def.Query == "":
		add(apierr.MissingSource, "", "the definition has neither \"table\" nor \"query\"", "Set \"table\": \"<table name>\" to expose a table as-is, or \"query\": \"<name>.query.ts\" to build the SQL in TypeScript (joins, conditions, computed columns).")
	case def.Query != "":
		def.QueryMod = loadQuery(def.Query, "/query")
	}

	if def.Command != "" {
		if def.ReadOnly {
			add(apierr.InvalidValue, "/readOnly", "the definition is readOnly but declares a command", "Remove \"readOnly\": true to accept writes, or remove \"command\".")
		}
		def.CommandMod = loadCommand(def.Command, "/command")
	}
	return def, errs
}

func queryExample(name string) string {
	if script.IsStarlark(name) {
		return "`def query(ctx):\n    return ctx.sql(\"SELECT ...\")`"
	}
	return "`export default function query(ctx: QueryContext): Sql { return ctx.sql`SELECT ...`; }`"
}

func commandExample(name string) string {
	if script.IsStarlark(name) {
		return "`def create(input, ctx): ... return new_id` (and/or `def update(input, ctx)`, `def remove(input, ctx)`)"
	}
	return "`export function create(input, ctx) { ... return id; }` (and/or `export function update(input, ctx)`, `export function remove(input, ctx)`)"
}

func handlerExample(name string) string {
	if script.IsStarlark(name) {
		return "`def handler(value, ctx):\n    return ctx.sql(\"stock > 0\")`"
	}
	return "`export default function handler(value, ctx) { return ctx.sql`stock > 0`; }`"
}

func commandFunction(method string) string {
	switch method {
	case "POST":
		return "create"
	case "DELETE":
		return "remove"
	}
	return "update"
}

func loadScript(def *Definition, name, pointer, file string, files map[string][]byte, lookup func(string) ([]byte, bool), engine script.Engine, notFound apierr.Code, example string) (script.Module, *apierr.Error) {
	src, ok := lookup(name)
	if !ok {
		var candidates []string
		for n := range files {
			if (strings.HasSuffix(n, ".ts") || strings.HasSuffix(n, ".js")) && n != file {
				candidates = append(candidates, n)
			}
		}
		sort.Strings(candidates)
		hint := fmt.Sprintf("Create %s in the definitions directory, next to %s, with `%s`.", name, file, example)
		if len(candidates) > 0 {
			hint += " Existing script files: " + strings.Join(candidates, ", ") + "."
		}
		return nil, &apierr.Error{Code: notFound, Resource: def.Name, Pointer: pointer, Message: fmt.Sprintf("file %q not found", name), Hint: hint}
	}
	mod, err := engine.Load(name, src)
	if err != nil {
		e, ok := apierr.As(err)
		if !ok {
			e = &apierr.Error{Code: apierr.ScriptError, Message: err.Error()}
		}
		e.Resource, e.Pointer = def.Name, pointer
		return nil, e
	}
	return mod, nil
}

// orderedProperties decodes a properties-like object keeping key order,
// which is the output (and CSV column) order. nil when raw is absent.
func orderedProperties(raw json.RawMessage) []Property {
	if len(raw) == 0 {
		return nil
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	if t, err := dec.Token(); err != nil || t != json.Delim('{') {
		return nil
	}
	props := []Property{}
	for dec.More() {
		k, err := dec.Token()
		if err != nil {
			return props
		}
		var p Property
		if err := dec.Decode(&p); err != nil {
			return props
		}
		p.Name = k.(string)
		props = append(props, p)
	}
	return props
}

func jsonError(file string, src []byte, err error) *apierr.Error {
	offset := int64(-1)
	var syn *json.SyntaxError
	var typ *json.UnmarshalTypeError
	switch {
	case errors.As(err, &syn):
		offset = syn.Offset
	case errors.As(err, &typ):
		offset = typ.Offset
	}
	msg := fmt.Sprintf("%s is not valid JSON: %s", file, err.Error())
	if offset >= 0 {
		line, col := 1, 1
		for _, c := range src[:min(int(offset), len(src))] {
			if c == '\n' {
				line++
				col = 1
			} else {
				col++
			}
		}
		msg = fmt.Sprintf("%s is not valid JSON at line %d, column %d: %s", file, line, col, err.Error())
	}
	return &apierr.Error{Code: apierr.InvalidJSON, Resource: file, Message: msg, Hint: "JSON has no comments and no trailing commas, keys must be double-quoted, entries separated by commas. Use a .ts definition if you prefer TypeScript syntax."}
}

func pointer(loc []string) string {
	if len(loc) == 0 {
		return ""
	}
	escaped := make([]string, len(loc))
	for i, s := range loc {
		escaped[i] = strings.NewReplacer("~", "~0", "/", "~1").Replace(s)
	}
	return "/" + strings.Join(escaped, "/")
}

func leaves(e *jsonschema.ValidationError, out *[]*jsonschema.ValidationError) {
	if len(e.Causes) == 0 {
		*out = append(*out, e)
		return
	}
	for _, c := range e.Causes {
		leaves(c, out)
	}
}

// schemaAt returns the schema node describing the instance at loc, and the
// nearest x-hint on the way (array items inherit the hint of their array).
func schemaAt(loc []string) (map[string]any, string) {
	node := schemaDoc
	hint, _ := node["x-hint"].(string)
	for _, seg := range loc {
		var next map[string]any
		if props, ok := node["properties"].(map[string]any); ok {
			next, _ = props[seg].(map[string]any)
		}
		if next == nil {
			if ap, ok := node["additionalProperties"].(map[string]any); ok {
				next = ap
			} else if items, ok := node["items"].(map[string]any); ok {
				next = items
			}
		}
		if next == nil {
			return nil, ""
		}
		if ref, ok := next["$ref"].(string); ok {
			defs, _ := schemaDoc["$defs"].(map[string]any)
			next, _ = defs[strings.TrimPrefix(ref, "#/$defs/")].(map[string]any)
		}
		node = next
		if h, ok := node["x-hint"].(string); ok {
			hint = h
		}
	}
	return node, hint
}

func allowedKeys(loc []string) []string {
	node, _ := schemaAt(loc)
	props, _ := node["properties"].(map[string]any)
	keys := make([]string, 0, len(props))
	for k := range props {
		if k != "$schema" {
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)
	return keys
}

func fieldHint(loc []string) string {
	_, h := schemaAt(loc)
	return h
}

var methodPointer = regexp.MustCompile(`^/operations/\d+/method(/\d+)?$`)

func schemaErrors(resource string, verr *jsonschema.ValidationError) apierr.List {
	var ls []*jsonschema.ValidationError
	leaves(verr, &ls)
	var out apierr.List
	seen := map[string]bool{}
	add := func(e *apierr.Error) {
		if methodPointer.MatchString(e.Pointer) {
			e.Topic = "operations"
		}
		key := string(e.Code) + e.Pointer
		if !seen[key] {
			seen[key] = true
			out = append(out, e)
		}
	}
	for _, l := range ls {
		loc := l.InstanceLocation
		ptr := pointer(loc)
		where := ptr
		if where == "" {
			where = "the definition root"
		}
		switch k := l.ErrorKind.(type) {
		case *kind.AdditionalProperties:
			allowed := allowedKeys(loc)
			for _, p := range k.Properties {
				hint := "Allowed keys here: " + strings.Join(allowed, ", ") + "."
				if s := apierr.Suggest(p, allowed); s != "" {
					hint = fmt.Sprintf("Did you mean %q? ", s) + hint
				}
				add(&apierr.Error{Code: apierr.UnknownField, Resource: resource, Pointer: pointer(append(slices.Clone(loc), p)), Message: fmt.Sprintf("unknown key %q in %s", p, where), Hint: hint})
			}
		case *kind.Required:
			for _, m := range k.Missing {
				p := append(slices.Clone(loc), m)
				hint := fieldHint(p)
				if node, _ := schemaAt(p); node != nil {
					d, _ := node["description"].(string)
					hint = strings.TrimSpace(d + " " + hint)
				}
				add(&apierr.Error{Code: apierr.MissingField, Resource: resource, Pointer: pointer(p), Message: fmt.Sprintf("required key %q is missing in %s", m, where), Hint: hint})
			}
		case *kind.Type:
			add(&apierr.Error{Code: apierr.InvalidType, Resource: resource, Pointer: ptr, Message: fmt.Sprintf("%s must be of JSON type %s, got %s", where, strings.Join(k.Want, " or "), k.Got), Hint: fieldHint(loc)})
		case *kind.Enum:
			want := make([]string, len(k.Want))
			for i, w := range k.Want {
				want[i] = fmt.Sprint(w)
			}
			hint := "Allowed values: " + strings.Join(want, ", ") + "."
			if s := apierr.Suggest(fmt.Sprint(k.Got), want); s != "" {
				hint = fmt.Sprintf("Did you mean %q? ", s) + hint
			}
			add(&apierr.Error{Code: apierr.InvalidValue, Resource: resource, Pointer: ptr, Message: fmt.Sprintf("%s: %v is not an allowed value", where, k.Got), Hint: hint})
		case *kind.Pattern:
			add(&apierr.Error{Code: apierr.InvalidValue, Resource: resource, Pointer: ptr, Message: fmt.Sprintf("%s: %q has an invalid format", where, k.Got), Hint: fieldHint(loc)})
		case *kind.PropertyNames:
			p := pointer(append(slices.Clone(loc), k.Property))
			add(&apierr.Error{Code: apierr.InvalidValue, Resource: resource, Pointer: p, Message: fmt.Sprintf("property name %q is invalid", k.Property), Hint: fieldHint(loc)})
		case *kind.MinProperties:
			add(&apierr.Error{Code: apierr.MissingField, Resource: resource, Pointer: ptr, Message: fmt.Sprintf("%s must declare at least %d entry", where, k.Want), Hint: fieldHint(loc)})
		case *kind.Minimum, *kind.Maximum:
			add(&apierr.Error{Code: apierr.InvalidValue, Resource: resource, Pointer: ptr, Message: fmt.Sprintf("%s is out of range", where), Hint: fieldHint(loc)})
		default:
			add(&apierr.Error{Code: apierr.InvalidValue, Resource: resource, Pointer: ptr, Message: fmt.Sprintf("%s: %s", where, l.Error()), Hint: fieldHint(loc)})
		}
	}
	return out
}

func headerPrefixWarnings(d *Definition) apierr.List {
	var warns apierr.List
	check := func(params []Parameter, at string) {
		for _, p := range params {
			if p.In != "header" || len(p.Name) < 3 || !strings.EqualFold(p.Name[:2], "x-") {
				continue
			}
			warns = append(warns, &apierr.Error{Code: apierr.DeprecatedHeaderPrefix, Resource: d.Name, Pointer: at + "/" + escapePointer(p.Name), Message: fmt.Sprintf("header parameter %q uses the X- prefix", p.Name), Hint: fmt.Sprintf("RFC 6648 deprecates the X- prefix for new headers: name it %s.", p.Name[2:])})
		}
	}
	check(d.Parameters, "/parameters")
	for i, op := range d.Ops {
		if i > 0 && d.Ops[i-1].Index == op.Index {
			continue
		}
		check(op.Parameters, fmt.Sprintf("/operations/%d/parameters", op.Index))
	}
	return warns
}

func (op Operation) methodList() []string {
	if op.Methods != nil {
		return op.Methods
	}
	return []string{op.Method}
}

// validateMultiMethod checks an operation declared with a method array: one
// explicit handler serves every method, so the handler kind picks the flow.
func validateMultiMethod(def *Definition, op Operation, ptr string) apierr.List {
	var errs apierr.List
	add := func(code apierr.Code, pointer, msg, hint string) {
		errs = append(errs, &apierr.Error{Code: code, Resource: def.Name, Pointer: pointer, Message: msg, Hint: hint, Topic: "operations"})
	}
	methods := strings.Join(op.Methods, ", ")
	switch {
	case op.Query == "" && op.Command == "":
		add(apierr.MissingSource, ptr, fmt.Sprintf("operation [%s] %s declares several methods without a handler", methods, op.Path), "Default behaviour differs per method, so an operation with a method array needs one handler: \"query\" (read flow for every method) or \"command\" (write flow for every method).")
	case op.Query != "" && op.Command != "":
		add(apierr.InvalidValue, ptr, fmt.Sprintf("operation [%s] %s declares both query and command", methods, op.Path), "An operation with a method array has one handler: keep \"query\" or \"command\", not both.")
	case op.Command != "":
		if slices.Contains(op.Methods, "GET") {
			add(apierr.InvalidValue, ptr+"/method", fmt.Sprintf("operation [%s] %s has a command but lists GET", methods, op.Path), "A command handles POST, PUT, PATCH and DELETE; use \"query\" as the handler to serve GET.")
		} else if op.CommandMod != nil {
			for _, m := range op.Methods {
				fnOp := map[string]string{"POST": "create", "PUT": "update", "PATCH": "update", "DELETE": "delete"}[m]
				if CommandExport(op.CommandMod, fnOp) == "" {
					name := fnOp
					if name == "delete" {
						name = "remove"
					}
					errs = append(errs, &apierr.Error{Code: apierr.OperationNotSupported, Resource: def.Name, Pointer: ptr + "/command", Message: fmt.Sprintf("%s does not define %s, needed by %s %s", op.Command, name, m, op.Path), Hint: fmt.Sprintf("The command function is chosen by method (create POST, update PUT/PATCH, remove DELETE): add %s(input, ctx) to %s.", name, op.Command), Topic: "operations"})
				}
			}
		}
	}
	return errs
}
