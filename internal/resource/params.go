package resource

import (
	"context"
	"fmt"
	"net/url"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

// Exec is what the parameters made of one request: conditions and order
// over the query result, pagination, and the values scripts read with
// ctx.get.
type Exec struct {
	Where        []sqlf.Fragment
	Order        []sqlf.Order
	Page         int
	ItemsPerPage int
	// Filters are the exact-match values, passed as ctx.filters to queries
	// returning rows.
	Filters map[string]any
	Values  map[string]any
}

var pageParameter = Parameter{Name: "page", In: "query", Type: "integer", Pagination: "page"}

// effectiveParameters adds what a definition gets without declaring it: the
// page parameter, and an exact filter per "filterable" property.
func effectiveParameters(d *Definition) []Parameter {
	params := slices.Clone(d.Parameters)
	has := func(name string) bool {
		return slices.ContainsFunc(params, func(p Parameter) bool { return p.Name == name })
	}
	if !slices.ContainsFunc(params, func(p Parameter) bool { return p.Pagination == "page" }) && !has("page") {
		params = append(params, pageParameter)
	}
	for _, p := range d.Properties {
		if p.Filterable && !has(p.Name) {
			params = append(params, Parameter{Name: p.Name, In: "query", Type: p.Type, Filter: "exact", column: p.Name})
		}
	}
	return params
}

// Prepare parses and validates the parameters of a request and runs their
// handlers. Parameters declaring a status run first, so an authentication
// header is checked before anything else. collection is false for item
// reads and writes: only header parameters apply there.
func (r *Runtime) Prepare(ctx context.Context, d *Definition, req script.Request, collection bool) (*Exec, error) {
	ex := &Exec{Page: 1, ItemsPerPage: d.ItemsPerPage, Filters: map[string]any{}, Values: map[string]any{}}
	params := effectiveParameters(d)
	if collection && len(d.Parameters) == 0 {
		if err := checkUndeclaredFilters(d, req.Query); err != nil {
			return nil, err
		}
	}
	ordered := make([]Parameter, 0, len(params))
	for _, p := range params {
		if p.Status != 0 {
			ordered = append(ordered, p)
		}
	}
	for _, p := range params {
		if p.Status == 0 {
			ordered = append(ordered, p)
		}
	}
	var order []sqlf.Order
	for _, p := range ordered {
		if !p.AppliesTo(collection) {
			continue
		}
		var err error
		switch {
		case p.Filter == "order":
			order, err = orderTerms(d, p, req.RawQuery)
			if len(order) > 0 {
				names := make([]any, len(order))
				for i, o := range order {
					names[i] = o.Column
				}
				ex.Values[p.Name] = names
			}
		case p.Filter == "range" || p.Filter == "date":
			err = applyOperators(ex, d, p, req.Query)
		default:
			err = r.applyValue(ctx, ex, d, p, req)
		}
		if err != nil {
			return nil, err
		}
	}
	if !collection {
		return ex, nil
	}
	if len(order) == 0 {
		order = slices.Clone(d.Order)
	}
	if id := d.Identifier().Name; id != "" && !slices.ContainsFunc(order, func(o sqlf.Order) bool { return o.Column == id }) {
		order = append(order, sqlf.Order{Column: id})
	}
	ex.Order = order
	return ex, nil
}

func checkUndeclaredFilters(d *Definition, q url.Values) error {
	for _, p := range d.Properties {
		if _, ok := q[p.Name]; !ok || p.Filterable {
			continue
		}
		var enabled []string
		for _, f := range d.Filterable() {
			enabled = append(enabled, f.Name)
		}
		hint := fmt.Sprintf("Add \"filterable\": true to properties.%s in the %s definition to filter on it, or declare \"parameters\" (then undeclared query parameters are ignored).", p.Name, d.Name)
		if len(enabled) > 0 {
			hint += " Filterable now: " + strings.Join(enabled, ", ") + "."
		}
		return &apierr.Error{Code: apierr.FilterNotEnabled, Resource: d.Name, Pointer: "/properties/" + p.Name, Message: fmt.Sprintf("property %q is not filterable", p.Name), Hint: hint}
	}
	return nil
}

func paramError(d *Definition, p Parameter, code apierr.Code, msg, hint string) *apierr.Error {
	e := &apierr.Error{Code: code, Resource: d.Name, Pointer: "/parameters/" + escapePointer(p.Name), Message: msg, Hint: hint}
	if p.Status != 0 {
		e.HTTPStatus = p.Status
	}
	return e
}

func rawValues(p Parameter, req script.Request) []string {
	if p.In == "header" {
		return req.Header.Values(p.Name)
	}
	vals := req.Query[p.Name]
	if p.Filter == "exact" {
		vals = append(vals, req.Query[p.Name+"[]"]...)
	}
	return vals
}

func (r *Runtime) applyValue(ctx context.Context, ex *Exec, d *Definition, p Parameter, req script.Request) error {
	raw := rawValues(p, req)
	if len(raw) == 0 {
		if p.Required {
			where := "query parameter"
			if p.In == "header" {
				where = "header"
			}
			return paramError(d, p, apierr.MissingParameter, fmt.Sprintf("%s %s is required", where, p.Name), parameterHint(p))
		}
		return nil
	}
	values := make([]any, 0, len(raw))
	for _, s := range raw {
		v, err := parseParameter(p, s)
		if err != nil {
			code := apierr.InvalidParameter
			if p.Name == pageParameter.Name && p.Pagination == "page" && p.Status == 0 {
				code = apierr.InvalidPage
			}
			return paramError(d, p, code, err.Error(), parameterHint(p))
		}
		values = append(values, v)
	}
	value := values[0]
	ex.Values[p.Name] = value
	switch p.Pagination {
	case "page":
		n := value.(int64)
		if n < 1 {
			code := apierr.InvalidParameter
			if p.Name == pageParameter.Name && p.Status == 0 {
				code = apierr.InvalidPage
			}
			return paramError(d, p, code, fmt.Sprintf("%s must be 1 or more, got %d", p.Name, n), "Pages start at 1: ?page=1, ?page=2, ...")
		}
		ex.Page = int(n)
	case "itemsPerPage":
		n := value.(int64)
		if n < 1 {
			return paramError(d, p, apierr.InvalidParameter, fmt.Sprintf("%s must be 1 or more, got %d", p.Name, n), parameterHint(p))
		}
		ex.ItemsPerPage = int(n)
	}
	switch p.Filter {
	case "exact":
		ex.Filters[p.Name] = value
		if len(values) > 1 {
			ex.Where = append(ex.Where, sqlf.In(p.Column(), typedSlice(values)))
		} else {
			ex.Where = append(ex.Where, exactCondition(p, value))
		}
	case "partial", "ipartial":
		ex.Where = append(ex.Where, sqlf.Like(p.Column(), value.(string), p.Filter == "ipartial"))
	case "boolean":
		ex.Where = append(ex.Where, sqlf.Eq(p.Column(), value))
	}
	if p.HandlerMod != nil {
		return r.runHandler(ctx, ex, d, p, req, value)
	}
	return nil
}

func exactCondition(p Parameter, v any) sqlf.Fragment {
	if p.Type == "date" {
		return sqlf.CmpCast(p.Column(), "=", v, "date")
	}
	return sqlf.Eq(p.Column(), v)
}

func typedSlice(values []any) any {
	switch values[0].(type) {
	case int64:
		out := make([]int64, len(values))
		for i, v := range values {
			out[i] = v.(int64)
		}
		return out
	case float64:
		out := make([]float64, len(values))
		for i, v := range values {
			out[i] = v.(float64)
		}
		return out
	case bool:
		out := make([]bool, len(values))
		for i, v := range values {
			out[i] = v.(bool)
		}
		return out
	case time.Time:
		out := make([]time.Time, len(values))
		for i, v := range values {
			out[i] = v.(time.Time)
		}
		return out
	}
	out := make([]string, len(values))
	for i, v := range values {
		out[i] = fmt.Sprint(v)
	}
	return out
}

var comparison = map[string]string{"gt": ">", "gte": ">=", "lt": "<", "lte": "<=", "after": ">=", "before": "<=", "strictly_after": ">", "strictly_before": "<"}

// applyOperators handles range (name[gte]=...) and date (name[after]=...)
// filters. Unknown operators are ignored like any undeclared parameter.
func applyOperators(ex *Exec, d *Definition, p Parameter, q url.Values) error {
	ops := RangeOperators
	if p.Filter == "date" {
		ops = DateOperators
	}
	found := map[string]any{}
	for _, op := range ops {
		key := p.Name + "[" + op + "]"
		raw, ok := q[key]
		if !ok || len(raw) == 0 {
			continue
		}
		v, err := parseParameter(Parameter{Name: key, Type: p.Type, Min: p.Min, Max: p.Max}, raw[0])
		if err != nil {
			return paramError(d, p, apierr.InvalidParameter, err.Error(), parameterHint(p))
		}
		found[op] = v
		if p.Type == "date" {
			ex.Where = append(ex.Where, sqlf.CmpCast(p.Column(), comparison[op], v, "date"))
		} else {
			ex.Where = append(ex.Where, sqlf.Cmp(p.Column(), comparison[op], v))
		}
	}
	if len(found) > 0 {
		ex.Values[p.Name] = found
	} else if p.Required {
		return paramError(d, p, apierr.MissingParameter, fmt.Sprintf("query parameter %s[...] is required", p.Name), parameterHint(p))
	}
	return nil
}

// orderTerms reads order[<property>]=asc|desc in request order.
func orderTerms(d *Definition, p Parameter, rawQuery string) ([]sqlf.Order, error) {
	var out []sqlf.Order
	prefix := p.Name + "["
	for _, pair := range strings.Split(rawQuery, "&") {
		k, v, _ := strings.Cut(pair, "=")
		key, err1 := url.QueryUnescape(k)
		val, err2 := url.QueryUnescape(v)
		if err1 != nil || err2 != nil || !strings.HasPrefix(key, prefix) || !strings.HasSuffix(key, "]") {
			continue
		}
		prop := key[len(prefix) : len(key)-1]
		col, ok := p.SortColumn(prop)
		if !ok {
			return nil, paramError(d, p, apierr.InvalidParameter, fmt.Sprintf("cannot sort on %q", prop), fmt.Sprintf("Sortable: %s, as %s[<property>]=asc|desc.", strings.Join(p.Properties, ", "), p.Name))
		}
		var desc bool
		switch strings.ToLower(val) {
		case "asc":
		case "desc":
			desc = true
		default:
			return nil, paramError(d, p, apierr.InvalidParameter, fmt.Sprintf("%s: direction %q is not asc or desc", key, val), "Use asc or desc.")
		}
		if slices.ContainsFunc(out, func(o sqlf.Order) bool { return o.Column == col }) {
			continue
		}
		out = append(out, sqlf.Order{Column: col, Desc: desc})
	}
	return out, nil
}

func parameterHint(p Parameter) string {
	switch {
	case p.Filter == "order":
		return fmt.Sprintf("Sortable: %s, as %s[<property>]=asc|desc.", strings.Join(p.Properties, ", "), p.Name)
	case p.Filter == "range":
		return fmt.Sprintf("Send %s[gt|gte|lt|lte]=<%s>.", p.Name, p.Type)
	case p.Filter == "date":
		return fmt.Sprintf("Send %s[after|before|strictly_after|strictly_before]=YYYY-MM-DD.", p.Name)
	}
	where := "?" + p.Name + "=<value>"
	if p.In == "header" {
		where = "the " + p.Name + " header"
	}
	return fmt.Sprintf("Send %s as %s.", where, describeParam(p))
}

func describeParam(p Parameter) string {
	var b strings.Builder
	switch p.Type {
	case "integer":
		b.WriteString("an integer")
	case "number":
		b.WriteString("a number")
	case "boolean":
		b.WriteString("true or false")
	case "date":
		b.WriteString("a YYYY-MM-DD date")
	case "datetime":
		b.WriteString("an RFC 3339 datetime")
	default:
		b.WriteString("a string")
	}
	switch {
	case p.Min != nil && p.Max != nil:
		fmt.Fprintf(&b, " between %s and %s", num(*p.Min), num(*p.Max))
	case p.Min != nil:
		fmt.Fprintf(&b, " of at least %s", num(*p.Min))
	case p.Max != nil:
		fmt.Fprintf(&b, " of at most %s", num(*p.Max))
	}
	if len(p.Enum) > 0 {
		fmt.Fprintf(&b, " among %s", strings.Join(p.Enum, ", "))
	}
	return b.String()
}

func num(f float64) string {
	return strconv.FormatFloat(f, 'f', -1, 64)
}

// parseParameter converts one raw value to the parameter type and checks
// its bounds. Dates stay YYYY-MM-DD strings (validated), datetimes become
// time.Time.
func parseParameter(p Parameter, s string) (any, error) {
	bad := func() error {
		return fmt.Errorf("%s must be %s, got %q", p.Name, describeParam(p), s)
	}
	var v any
	switch p.Type {
	case "integer":
		i, err := strconv.ParseInt(strings.TrimSpace(s), 10, 64)
		if err != nil {
			return nil, bad()
		}
		v = i
	case "number":
		f, err := strconv.ParseFloat(strings.TrimSpace(s), 64)
		if err != nil {
			return nil, bad()
		}
		v = f
	case "boolean":
		switch s {
		case "true", "1":
			v = true
		case "false", "0":
			v = false
		default:
			return nil, bad()
		}
	case "date":
		if !isDate(s) {
			return nil, bad()
		}
		v = s
	case "datetime":
		t, err := ParseValue("datetime", s)
		if err != nil {
			return nil, bad()
		}
		v = t
	default:
		v = s
	}
	var f float64
	switch x := v.(type) {
	case int64:
		f = float64(x)
	case float64:
		f = x
	default:
		if len(p.Enum) > 0 && !slices.Contains(p.Enum, s) {
			return nil, bad()
		}
		return v, nil
	}
	if (p.Min != nil && f < *p.Min) || (p.Max != nil && f > *p.Max) {
		return nil, bad()
	}
	return v, nil
}

func isDate(s string) bool {
	if len(s) != 10 {
		return false
	}
	_, err := time.Parse("2006-01-02", s)
	return err == nil
}

func (r *Runtime) runHandler(ctx context.Context, ex *Exec, d *Definition, p Parameter, req script.Request, value any) error {
	v, err := p.HandlerMod.Call(ctx, script.HandlerFunc, script.Call{Request: req, Host: r.host(r.db(), d, "/parameters/"+escapePointer(p.Name)+"/handler"), Operation: "parameter", Phase: "read", Value: value, Values: ex.Values, Resources: r.resources(), Property: d.propertyColumn})
	if err != nil {
		if e, ok := apierr.As(err); ok && e.Rejected && e.HTTPStatus == 0 {
			e.HTTPStatus = p.ErrorStatus()
		}
		return tag(err, d, "/parameters/"+escapePointer(p.Name)+"/handler")
	}
	switch x := v.(type) {
	case nil:
	case sqlf.Fragment:
		if !x.IsEmpty() {
			ex.Where = append(ex.Where, x)
		}
	default:
		return &apierr.Error{Code: apierr.InvalidQueryResult, Resource: d.Name, Pointer: "/parameters/" + escapePointer(p.Name) + "/handler", Message: fmt.Sprintf("%s returned %s", p.Handler, describe(v)), Hint: "A handler returns a SQL condition over the query's columns (ctx.sql`stock > 0`), null to do nothing, or calls ctx.reject(...)."}
	}
	return nil
}

// DocParam is one concrete parameter name, for OpenAPI, the Hydra search
// template and MCP tool schemas.
type DocParam struct {
	Name        string
	Property    string
	In          string
	Type        string
	Format      string
	Min, Max    *float64
	Enum        []string
	Required    bool
	Description string
}

// DocParameters expands the parameters of d applying to collection reads
// (or to item requests) into the names a client sends.
func DocParameters(d *Definition, collection bool) []DocParam {
	var out []DocParam
	for _, p := range effectiveParameters(d) {
		if !p.AppliesTo(collection) {
			continue
		}
		base := DocParam{Name: p.Name, Property: p.Property, In: p.In, Type: p.Type, Min: p.Min, Max: p.Max, Enum: p.Enum, Required: p.Required, Description: p.Description}
		if base.Type == "date" {
			base.Type, base.Format = "string", "date"
		}
		if base.Type == "datetime" {
			base.Type, base.Format = "string", "date-time"
		}
		target := p.Property
		if target == "" && p.Filter != "" {
			target = p.Name
			base.Property = target
		}
		describe := func(s string) string {
			if p.Description != "" {
				return p.Description
			}
			return s
		}
		switch p.Filter {
		case "order":
			for _, prop := range p.Properties {
				out = append(out, DocParam{Name: p.Name + "[" + prop + "]", Property: prop, In: "query", Type: "string", Enum: []string{"asc", "desc"}, Description: describe("Sort on " + prop + "; several order[...] apply in the order sent, ties broken by the identifier.")})
			}
		case "range", "date":
			ops := RangeOperators
			if p.Filter == "date" {
				ops = DateOperators
			}
			for _, op := range ops {
				dp := base
				dp.Name, dp.Required = p.Name+"["+op+"]", false
				dp.Description = describe(fmt.Sprintf("%s %s the value.", target, map[string]string{"gt": "greater than", "gte": "greater than or equal to", "lt": "less than", "lte": "less than or equal to", "after": "on or after", "before": "on or before", "strictly_after": "strictly after", "strictly_before": "strictly before"}[op]))
				out = append(out, dp)
			}
		default:
			switch p.Filter {
			case "exact":
				base.Description = describe("Exact match on " + target + "; send " + p.Name + "[]= several times to match any of several values.")
			case "partial":
				base.Description = describe(target + " contains the value (case-sensitive).")
			case "ipartial":
				base.Description = describe(target + " contains the value (case-insensitive).")
			case "boolean":
				base.Description = describe(target + " is true or false.")
			}
			switch p.Pagination {
			case "page":
				base.Description = describe("Page number, from 1.")
				one := 1.0
				base.Min = &one
			case "itemsPerPage":
				base.Description = describe(fmt.Sprintf("Page size, default %d.", d.ItemsPerPage))
			}
			out = append(out, base)
		}
	}
	return out
}

// propertyColumn is ctx.property: r."<column>" for a property (or a nested
// author.name) of the query result the host wraps.
func (d *Definition) propertyColumn(name string) (sqlf.Fragment, error) {
	_, col, hint := d.resolveProperty(name)
	if col == "" {
		return sqlf.Fragment{}, &apierr.Error{Code: apierr.InvalidHelperCall, Resource: d.Name, Message: fmt.Sprintf("ctx.property(%q): %s has no such property", name, d.Name), Hint: hint}
	}
	return sqlf.Raw(sqlf.Col(col)), nil
}
