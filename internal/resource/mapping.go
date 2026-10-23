package resource

import (
	"bytes"
	"encoding/json"
	"fmt"
	"reflect"
	"strings"
	"time"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
)

// Field and Object keep the declared order of nested properties in JSON.
type Field struct {
	K string
	V any
}

type Object []Field

func (o Object) MarshalJSON() ([]byte, error) {
	var b bytes.Buffer
	enc := json.NewEncoder(&b)
	enc.SetEscapeHTML(false)
	b.WriteByte('{')
	for i, f := range o {
		if i > 0 {
			b.WriteByte(',')
		}
		if err := enc.Encode(f.K); err != nil {
			return nil, err
		}
		b.Truncate(b.Len() - 1)
		b.WriteByte(':')
		if err := enc.Encode(f.V); err != nil {
			return nil, err
		}
		b.Truncate(b.Len() - 1)
	}
	b.WriteByte('}')
	return b.Bytes(), nil
}

func (o Object) Get(k string) (any, bool) {
	for _, f := range o {
		if f.K == k {
			return f.V, true
		}
	}
	return nil, false
}

// mapRow builds an item from a result row: a column named like a property
// fills it; an object property without such a column is filled from the
// columns <property>_<nested> (author_id, author_name -> author: {id, name}),
// and is null when they are all NULL (a LEFT JOIN without match).
func mapRow(d *Definition, byCol map[string]any) (Item, error) {
	props := d.Output()
	item := make(Item, len(props))
	for _, p := range props {
		if v, ok := byCol[p.Name]; ok {
			item[p.Name] = outputValue(p, v)
			continue
		}
		if p.Type == "object" && len(p.Properties) > 0 {
			obj := make(Object, 0, len(p.Properties))
			allNull := true
			for _, s := range p.Properties {
				v, ok := byCol[p.Name+"_"+s.Name]
				if !ok {
					return nil, missingNested(d, p, s)
				}
				if v != nil {
					allNull = false
				}
				obj = append(obj, Field{s.Name, outputValue(s, v)})
			}
			if allNull {
				item[p.Name] = nil
			} else {
				item[p.Name] = obj
			}
			continue
		}
		return nil, &apierr.Error{Code: apierr.UnknownColumn, Resource: d.Name, Pointer: "/properties/" + p.Name, Message: fmt.Sprintf("column %q is not returned by the query for this request", p.Name), Hint: "The query must return the same columns for every request. Run `caddy resource validate`."}
	}
	if id, ok := d.HiddenIdentifier(); ok {
		if v, ok := byCol[id.Name]; ok {
			item[id.Name] = outputValue(id, v)
		}
	}
	return item, nil
}

func missingNested(d *Definition, p, s Property) *apierr.Error {
	return &apierr.Error{Code: apierr.UnknownColumn, Resource: d.Name, Pointer: "/properties/" + p.Name + "/properties/" + s.Name, Message: fmt.Sprintf("column %q is not returned by the query for this request", p.Name+"_"+s.Name), Hint: fmt.Sprintf("Object property %s is filled from a json column %q, or from one column per nested property named %s_<nested>.", p.Name, p.Name, p.Name)}
}

// outputValue converts a value from PostgreSQL, a json column or a script to
// the JSON form of the property type.
func outputValue(p Property, v any) any {
	if v == nil {
		return nil
	}
	switch p.Type {
	case "object":
		var m map[string]any
		switch x := v.(type) {
		case Object:
			return x
		case map[string]any:
			m = x
		case string:
			if json.Unmarshal([]byte(x), &m) != nil {
				return v
			}
		default:
			return v
		}
		if len(p.Properties) == 0 {
			return m
		}
		obj := make(Object, 0, len(p.Properties))
		for _, s := range p.Properties {
			obj = append(obj, Field{s.Name, outputValue(s, m[s.Name])})
		}
		return obj
	case "array":
		var list []any
		switch x := v.(type) {
		case []any:
			list = x
		case string:
			if json.Unmarshal([]byte(x), &list) != nil {
				return v
			}
		default:
			rv := reflect.ValueOf(v)
			if rv.Kind() != reflect.Slice {
				return v
			}
			for i := range rv.Len() {
				list = append(list, rv.Index(i).Interface())
			}
		}
		out := make([]any, len(list))
		for i, e := range list {
			if p.Items != nil {
				out[i] = outputValue(*p.Items, e)
			} else {
				out[i] = e
			}
		}
		return out
	case "date":
		switch x := v.(type) {
		case time.Time:
			return x.Format("2006-01-02")
		case string:
			if len(x) >= 10 && isDate(x[:10]) {
				return x[:10]
			}
		}
	case "datetime":
		if s, ok := v.(string); ok {
			if t, err := parseTimestamp(s); err == nil {
				return t.UTC().Format(time.RFC3339Nano)
			}
			return s
		}
	case "integer":
		if f, ok := v.(float64); ok && f == float64(int64(f)) {
			return int64(f)
		}
	}
	return toJSON(p.Type, v)
}

// parseTimestamp reads what json_build_object and scripts produce:
// RFC 3339, or PostgreSQL's "2026-01-15T10:00:00+00:00" text.
func parseTimestamp(s string) (time.Time, error) {
	for _, layout := range []string{time.RFC3339Nano, "2006-01-02T15:04:05.999999999-07", "2006-01-02 15:04:05.999999999-07:00", "2006-01-02 15:04:05.999999999-07"} {
		if t, err := time.Parse(layout, strings.TrimSpace(s)); err == nil {
			return t, nil
		}
	}
	return time.Time{}, errInvalid
}
