package sqlf

import (
	"strconv"
	"strings"

	"github.com/jackc/pgx/v5"
)

// Fragment is SQL text with positional arguments kept apart from the text:
// len(parts) == len(args)+1 and args[i] sits between parts[i] and parts[i+1].
type Fragment struct {
	parts []string
	args  []any
	// simple marks a single comparison built by the host, which needs no
	// parentheses when ANDed.
	simple bool
}

type Query struct {
	SQL  string
	Args []any
	// SourceOffset is where the wrapped source starts in SQL, used to map
	// PostgreSQL error positions back to the definition's own query.
	SourceOffset int
}

func Raw(sql string) Fragment {
	return Fragment{parts: []string{sql}}
}

func Tagged(strs []string, values []any) Fragment {
	f := Fragment{parts: []string{""}}
	for i, s := range strs {
		f.appendText(s)
		if i < len(values) {
			f.appendValue(values[i])
		}
	}
	return f
}

func (f *Fragment) appendText(s string) {
	f.parts[len(f.parts)-1] += s
}

func (f *Fragment) appendValue(v any) {
	if nested, ok := v.(Fragment); ok {
		f.appendFragment(nested)
		return
	}
	f.args = append(f.args, v)
	f.parts = append(f.parts, "")
}

func (f *Fragment) appendFragment(o Fragment) {
	if len(o.parts) == 0 {
		return
	}
	f.appendText(o.parts[0])
	for i, a := range o.args {
		f.args = append(f.args, a)
		f.parts = append(f.parts, o.parts[i+1])
	}
}

func (f Fragment) Render(offset int) (string, []any) {
	var b strings.Builder
	for i, p := range f.parts {
		b.WriteString(p)
		if i < len(f.args) {
			b.WriteString("$")
			b.WriteString(strconv.Itoa(offset + i + 1))
		}
	}
	return b.String(), append([]any(nil), f.args...)
}

func (f Fragment) IsEmpty() bool {
	return len(f.args) == 0 && strings.TrimSpace(strings.Join(f.parts, "")) == ""
}

func Ident(name string) string {
	return pgx.Identifier{name}.Sanitize()
}

func TableIdent(table string) string {
	return pgx.Identifier(strings.Split(table, ".")).Sanitize()
}

func TableSource(table string, columns []string) Fragment {
	quoted := make([]string, len(columns))
	for i, c := range columns {
		quoted[i] = Ident(c)
	}
	return Raw("SELECT " + strings.Join(quoted, ", ") + " FROM " + TableIdent(table))
}

type builder struct {
	sql          strings.Builder
	args         []any
	sourceOffset int
}

func (b *builder) text(s string) { b.sql.WriteString(s) }

func (b *builder) arg(v any) {
	b.args = append(b.args, v)
	b.sql.WriteString("$" + strconv.Itoa(len(b.args)))
}

// The source is wrapped on its own lines so a trailing "--" comment cannot
// swallow the closing parenthesis, and trailing semicolons are dropped because
// they are illegal inside a subquery.
func (b *builder) from(selectList string, src Fragment) {
	sql, args := src.Render(0)
	sql = strings.TrimRight(sql, " \t\r\n;")
	b.text("SELECT " + selectList + " FROM (\n")
	b.sourceOffset = b.sql.Len()
	b.text(sql + "\n) r")
	b.args = append(b.args, args...)
}

func (b *builder) frag(f Fragment) {
	sql, args := f.Render(len(b.args))
	b.text(sql)
	b.args = append(b.args, args...)
}

// where ANDs the conditions; each is parenthesized so an OR inside one
// cannot leak into the others.
func (b *builder) where(conds []Fragment) {
	for i, c := range conds {
		if i == 0 {
			b.text(" WHERE ")
		} else {
			b.text(" AND ")
		}
		if c.simple {
			b.frag(c)
			continue
		}
		b.text("(")
		b.frag(c)
		b.text(")")
	}
}

type Order struct {
	Column string
	Desc   bool
}

// Select is what the host adds around a source: conditions over its
// columns, an order (empty keeps the source's own order) and pagination
// (Limit 0 means no pagination).
type Select struct {
	Where  []Fragment
	Order  []Order
	Limit  int
	Offset int
}

func (b *builder) orderBy(order []Order) {
	for i, o := range order {
		if i == 0 {
			b.text(" ORDER BY ")
		} else {
			b.text(", ")
		}
		dir := " ASC"
		if o.Desc {
			dir = " DESC"
		}
		b.text("r." + Ident(o.Column) + dir)
	}
}

func (b *builder) query() Query {
	return Query{SQL: b.sql.String(), Args: b.args, SourceOffset: b.sourceOffset}
}

// bare emits the source itself: with no condition and no order to add,
// wrapping it would make its own ORDER BY unreliable (a subquery's order is
// not guaranteed to survive).
func (b *builder) bare(src Fragment) {
	sql, args := src.Render(0)
	b.text(strings.TrimRight(sql, " \t\r\n;"))
	b.args = append(b.args, args...)
}

func Collection(src Fragment, s Select) Query {
	var b builder
	if len(s.Where) == 0 && len(s.Order) == 0 {
		b.bare(src)
		b.text("\nLIMIT ")
		b.arg(s.Limit)
		b.text(" OFFSET ")
		b.arg(s.Offset)
		return b.query()
	}
	b.from("*", src)
	b.where(s.Where)
	b.orderBy(s.Order)
	b.text(" LIMIT ")
	b.arg(s.Limit)
	b.text(" OFFSET ")
	b.arg(s.Offset)
	return b.query()
}

func Count(src Fragment, where []Fragment) Query {
	var b builder
	b.from("count(*)", src)
	b.where(where)
	return b.query()
}

func Item(src Fragment, idColumn string, id any, where []Fragment) Query {
	var b builder
	b.from("*", src)
	b.where(append([]Fragment{Eq(idColumn, id)}, where...))
	return b.query()
}

func Probe(src Fragment) Query {
	var b builder
	b.from("*", src)
	b.text(" LIMIT 0")
	return b.query()
}

func All(src Fragment, s Select) Query {
	var b builder
	if len(s.Where) == 0 && len(s.Order) == 0 {
		b.bare(src)
		return b.query()
	}
	b.from("*", src)
	b.where(s.Where)
	b.orderBy(s.Order)
	return b.query()
}

// Col is the quoted reference to a column of the wrapped source.
func Col(name string) string {
	return "r." + Ident(name)
}

func cond(text string, v any, suffix string) Fragment {
	f := Fragment{parts: []string{text, suffix}, args: []any{v}, simple: true}
	return f
}

func Eq(column string, v any) Fragment {
	return cond(Col(column)+" = ", v, "")
}

func In(column string, values any) Fragment {
	return cond(Col(column)+" = ANY(", values, ")")
}

func Cmp(column, op string, v any) Fragment {
	return cond(Col(column)+" "+op+" ", v, "")
}

// CmpCast compares with a value cast to a SQL type, for values sent as text
// (dates) whose type PostgreSQL could not infer otherwise.
func CmpCast(column, op string, v any, sqlType string) Fragment {
	return cond(Col(column)+" "+op+" ", v, "::"+sqlType)
}

// Like matches a substring: the value is escaped, so % and _ sent by a client
// are literal characters, and bound as a parameter.
func Like(column, value string, insensitive bool) Fragment {
	op := " LIKE "
	if insensitive {
		op = " ILIKE "
	}
	return cond(Col(column)+"::text"+op, "%"+EscapeLike(value)+"%", ` ESCAPE '\'`)
}

func EscapeLike(s string) string {
	return strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(s)
}

// Join concatenates fragments with a separator, keeping their arguments.
func Join(frags []Fragment, sep string) Fragment {
	out := Fragment{parts: []string{""}}
	for i, f := range frags {
		if i > 0 {
			out.appendText(sep)
		}
		out.appendFragment(f)
	}
	return out
}
