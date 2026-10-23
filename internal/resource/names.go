package resource

import (
	"fmt"
	"regexp"
	"sort"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
)

// propertyNamePattern is the pattern of schema.json for property names: they
// are JSON keys, minus what parameters (order[name], author.name) and
// ctx.property paths reserve.
const propertyNamePattern = `^[^.\[\]\s\p{Z}\p{C}]+$`

var (
	propertyName     = regexp.MustCompile(propertyNamePattern)
	nonIdentChars    = regexp.MustCompile(`[^A-Za-z0-9_]+`)
	propertyNameHint = "A property name is a JSON key: any non-empty string without `.`, `[`, `]`, whitespace or control characters (`.` and brackets are reserved by parameter and ctx.property paths)."
)

// defaultColumn is the column of a property without explicit "column": its
// name when it is a SQL identifier, else with each run of other characters
// replaced by "_" (order-data -> order_data).
func defaultColumn(name string) string {
	if identPattern.MatchString(name) {
		return name
	}
	col := nonIdentChars.ReplaceAllString(name, "_")
	if !identPattern.MatchString(col) {
		col = "_" + col
	}
	return col
}

// propertyNameErrors checks the names of the properties of a raw definition
// document (properties, input and operation inputs, nested included). The
// schema holds the same rule, but its validator cannot locate a bad key.
func propertyNameErrors(resource string, doc any) apierr.List {
	var errs apierr.List
	var props func(v any, ptr string)
	var node func(v any, ptr string)
	props = func(v any, ptr string) {
		m, ok := v.(map[string]any)
		if !ok {
			return
		}
		keys := make([]string, 0, len(m))
		for k := range m {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			p := ptr + "/" + escapePointer(k)
			if !propertyName.MatchString(k) {
				errs = append(errs, &apierr.Error{Code: apierr.InvalidValue, Resource: resource, Pointer: p, Message: fmt.Sprintf("property name %q is invalid", k), Hint: propertyNameHint})
				continue
			}
			node(m[k], p)
		}
	}
	node = func(v any, ptr string) {
		m, ok := v.(map[string]any)
		if !ok {
			return
		}
		props(m["properties"], ptr+"/properties")
		node(m["items"], ptr+"/items")
	}
	root, ok := doc.(map[string]any)
	if !ok {
		return nil
	}
	props(root["properties"], "/properties")
	props(root["input"], "/input")
	if ops, ok := root["operations"].([]any); ok {
		for i, op := range ops {
			if m, ok := op.(map[string]any); ok {
				props(m["input"], fmt.Sprintf("/operations/%d/input", i))
			}
		}
	}
	return errs
}

// columnCollisions reports properties of one list mapping to the same table
// column: reads and generated writes would silently share it.
func columnCollisions(d *Definition, props []Property, ptr string) apierr.List {
	var errs apierr.List
	seen := map[string]string{}
	for _, p := range props {
		if p.Computed {
			continue
		}
		col := p.ColumnName()
		if other, ok := seen[col]; ok {
			errs = append(errs, &apierr.Error{Code: apierr.DuplicateColumn, Resource: d.Name, Pointer: ptr + "/" + escapePointer(p.Name), Message: fmt.Sprintf("properties %q and %q both map to column %q of table %q", other, p.Name, col, d.Table), Hint: `Set "column" on one of them to its own column. A property name that is not a SQL identifier maps to the name with runs of other characters replaced by "_".`})
			continue
		}
		seen[col] = p.Name
	}
	return errs
}

func validateColumns(d *Definition) apierr.List {
	if d.Table == "" {
		return nil
	}
	errs := columnCollisions(d, d.Properties, "/properties")
	errs = append(errs, columnCollisions(d, d.Input, "/input")...)
	for i, op := range d.Ops {
		errs = append(errs, columnCollisions(d, op.Input, fmt.Sprintf("/operations/%d/input", i))...)
	}
	return errs
}
