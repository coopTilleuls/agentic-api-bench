package dbtool

import (
	"bytes"
	"database/sql/driver"
	"encoding/json"
	"fmt"
	"io"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
)

const maxCell = 60

func textValue(v any) string {
	var s string
	switch x := v.(type) {
	case nil:
		return "NULL"
	case string:
		s = x
	case time.Time:
		s = x.UTC().Format(time.RFC3339Nano)
	case [16]byte:
		s = uuid.UUID(x).String()
	case []byte:
		s = fmt.Sprintf("\\x%x", x)
	case map[string]any, []any:
		b, _ := json.Marshal(x)
		s = string(b)
	case driver.Valuer:
		if dv, err := x.Value(); err == nil && dv != nil {
			return textValue(dv)
		}
		s = fmt.Sprint(v)
	default:
		s = fmt.Sprint(v)
	}
	s = strings.NewReplacer("\r", `\r`, "\n", `\n`, "\t", `\t`).Replace(s)
	if utf8.RuneCountInString(s) > maxCell {
		s = string([]rune(s)[:maxCell-1]) + "…"
	}
	return s
}

func jsonValue(v any) any {
	switch x := v.(type) {
	case time.Time:
		return x.UTC().Format(time.RFC3339Nano)
	case [16]byte:
		return uuid.UUID(x).String()
	case driver.Valuer:
		if dv, err := x.Value(); err == nil {
			if s, ok := dv.(string); ok {
				return json.Number(s)
			}
			return dv
		}
	}
	return v
}

type orderedRow struct {
	columns []string
	values  []any
}

func (r orderedRow) MarshalJSON() ([]byte, error) {
	var b bytes.Buffer
	b.WriteByte('{')
	for i, c := range r.columns {
		if i > 0 {
			b.WriteByte(',')
		}
		k, _ := json.Marshal(c)
		v, err := json.Marshal(jsonValue(r.values[i]))
		if err != nil {
			return nil, err
		}
		b.Write(k)
		b.WriteByte(':')
		b.Write(v)
	}
	b.WriteByte('}')
	return b.Bytes(), nil
}

// Rows is a result set: column names and one value slice per row.
type Rows struct {
	Columns []string
	Data    [][]any
}

func (r Rows) objects() []orderedRow {
	out := make([]orderedRow, len(r.Data))
	for i, d := range r.Data {
		out[i] = orderedRow{r.Columns, d}
	}
	return out
}

func (r Rows) MarshalJSON() ([]byte, error) {
	return json.Marshal(r.objects())
}

// writeTable prints an aligned table, cells separated by " | ", every line
// prefixed with indent and without trailing padding.
func (r Rows) writeTable(w io.Writer, indent string) {
	cells := make([][]string, len(r.Data))
	widths := make([]int, len(r.Columns))
	for i, c := range r.Columns {
		widths[i] = utf8.RuneCountInString(c)
	}
	for i, row := range r.Data {
		cells[i] = make([]string, len(row))
		for j, v := range row {
			cells[i][j] = textValue(v)
			if n := utf8.RuneCountInString(cells[i][j]); n > widths[j] {
				widths[j] = n
			}
		}
	}
	line := func(vals []string) {
		var b strings.Builder
		b.WriteString(indent)
		for j, v := range vals {
			if j > 0 {
				b.WriteString(" | ")
			}
			b.WriteString(v)
			if j < len(vals)-1 {
				b.WriteString(strings.Repeat(" ", widths[j]-utf8.RuneCountInString(v)))
			}
		}
		fmt.Fprintln(w, b.String())
	}
	line(r.Columns)
	for _, row := range cells {
		line(row)
	}
}
