package resource

import (
	"fmt"
	"regexp"
	"sort"
	"strings"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

type resources struct{ set *Set }

func (r *Runtime) resources() script.Resources {
	if r.Set == nil {
		return nil
	}
	return resources{r.Set}
}

func helperErr(msg, hint string) *apierr.Error {
	return &apierr.Error{Code: apierr.InvalidHelperCall, Message: msg, Hint: hint}
}

func (rs resources) Resource(name string) (script.SQLHelper, error) {
	d := rs.set.ByName(name)
	if d == nil {
		var names []string
		for _, x := range rs.set.Defs {
			names = append(names, x.Name)
		}
		sort.Strings(names)
		hint := "Resources: " + strings.Join(names, ", ") + "."
		if s := apierr.Suggest(name, names); s != "" {
			hint = fmt.Sprintf("Did you mean %q? ", s) + hint
		}
		return nil, helperErr(fmt.Sprintf("ctx.resource(%q): no such resource", name), hint)
	}
	if d.Table == "" {
		return nil, helperErr(fmt.Sprintf("ctx.resource(%q): %s declares no table", name, name), fmt.Sprintf("Add \"table\" to %s (it keeps its \"query\"): helpers generate SQL from the table and its columns.", d.File))
	}
	alias := d.Table[strings.LastIndex(d.Table, ".")+1:]
	return &helper{set: rs.set, d: d, alias: alias}, nil
}

// helper generates SQL for one resource. The root helper aliases columns to
// property names; a relation helper prefixes them with the relation name
// (author_name), which is what the row mapper reads into nested objects.
type helper struct {
	set    *Set
	d      *Definition
	alias  string
	prefix string
	// nested is the parent property this relation fills; its properties are
	// the default column list.
	nested *Property
}

var identPattern = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)

func (h *helper) qualified(col string) string {
	return sqlf.Ident(h.alias) + "." + sqlf.Ident(col)
}

func (h *helper) Table() sqlf.Fragment {
	return sqlf.Raw(sqlf.TableIdent(h.d.Table) + " AS " + sqlf.Ident(h.alias))
}

func (h *helper) Alias() string {
	return h.alias
}

func (h *helper) columnOf(name string) (string, error) {
	if p, ok := h.d.Property(name); ok {
		if !p.Scalar() || p.Computed {
			return "", helperErr(fmt.Sprintf("%s.%s is not a column of table %s", h.d.Name, name, h.d.Table), "Object, array and computed properties are not table columns: build them in the query.")
		}
		return p.ColumnName(), nil
	}
	if !identPattern.MatchString(name) {
		return "", helperErr(fmt.Sprintf("%q is neither a property of %s nor a column name", name, h.d.Name), "Pass a property name, or a plain column name of table "+h.d.Table+".")
	}
	return name, nil
}

// Column accepts a property (mapped to its column) or a plain column name of
// the table, for columns the resource does not expose (published).
func (h *helper) Column(name string) (sqlf.Fragment, error) {
	col, err := h.columnOf(name)
	if err != nil {
		return sqlf.Fragment{}, err
	}
	return sqlf.Raw(h.qualified(col)), nil
}

func (h *helper) defaultNames() []string {
	if h.nested != nil && len(h.nested.Properties) > 0 {
		return propertyNames(h.nested.Properties)
	}
	var out []string
	for _, p := range h.d.Properties {
		if p.Scalar() && !p.Computed {
			out = append(out, p.Name)
		}
	}
	return out
}

func (h *helper) Columns(names []string) (sqlf.Fragment, error) {
	if names == nil {
		names = h.defaultNames()
	}
	parts := make([]string, 0, len(names))
	for _, n := range names {
		col, err := h.columnOf(n)
		if err != nil {
			return sqlf.Fragment{}, err
		}
		parts = append(parts, h.qualified(col)+" AS "+sqlf.Ident(h.prefix+n))
	}
	return sqlf.Raw(strings.Join(parts, ", ")), nil
}

func (h *helper) Identifier(v any) sqlf.Fragment {
	id := h.d.Identifier()
	return sqlf.Tagged([]string{h.qualified(id.ColumnName()) + " = ", ""}, []any{v})
}

func (h *helper) relation(name string) (Relation, *Definition, error) {
	rel, ok := h.d.Relations[name]
	if !ok {
		names := make([]string, 0, len(h.d.Relations))
		for n := range h.d.Relations {
			names = append(names, n)
		}
		sort.Strings(names)
		hint := fmt.Sprintf("Declare it in \"relations\" of %s.", h.d.File)
		if len(names) > 0 {
			hint = "Relations of " + h.d.Name + ": " + strings.Join(names, ", ") + ". " + hint
		}
		return Relation{}, nil, helperErr(fmt.Sprintf("%s has no relation %q", h.d.Name, name), hint)
	}
	other := h.set.ByName(rel.Resource)
	if other == nil || other.Table == "" {
		return Relation{}, nil, helperErr(fmt.Sprintf("relation %s.%s targets %s, which is not a table resource", h.d.Name, name, rel.Resource), "The related resource must declare \"table\".")
	}
	return rel, other, nil
}

// Join is the join condition between this resource and a relation, using
// the relation helper's alias: ON ${book.join("author")}.
func (h *helper) Join(name string) (sqlf.Fragment, error) {
	rel, other, err := h.relation(name)
	if err != nil {
		return sqlf.Fragment{}, err
	}
	relAlias := sqlf.Ident(h.alias + "_" + name)
	if rel.Many {
		return sqlf.Raw(relAlias + "." + sqlf.Ident(rel.ForeignKey) + " = " + h.qualified(h.d.Identifier().ColumnName())), nil
	}
	return sqlf.Raw(h.qualified(rel.Column) + " = " + relAlias + "." + sqlf.Ident(other.Identifier().ColumnName())), nil
}

func (h *helper) Relation(name string) (script.SQLHelper, error) {
	_, other, err := h.relation(name)
	if err != nil {
		return nil, err
	}
	sub := &helper{set: h.set, d: other, alias: h.alias + "_" + name, prefix: h.prefix + name + "_"}
	if p, ok := h.d.Property(name); ok {
		switch {
		case p.Type == "object":
			sub.nested = &p
		case p.Type == "array" && p.Items != nil && p.Items.Type == "object":
			items := *p.Items
			sub.nested = &items
		}
	}
	return sub, nil
}

// JSON builds json_build_object('<name>', <column>, ...), for json_agg of a
// to-many relation.
func (h *helper) JSON(names []string) (sqlf.Fragment, error) {
	if names == nil {
		names = h.defaultNames()
	}
	parts := make([]string, 0, len(names))
	for _, n := range names {
		col, err := h.columnOf(n)
		if err != nil {
			return sqlf.Fragment{}, err
		}
		parts = append(parts, "'"+strings.ReplaceAll(n, "'", "''")+"', "+h.qualified(col))
	}
	return sqlf.Raw("json_build_object(" + strings.Join(parts, ", ") + ")"), nil
}
