package apierr

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"slices"
	"strings"

	"github.com/jackc/pgx/v5/pgconn"
)

type Code string

type Error struct {
	Code     Code   `json:"code"`
	Resource string `json:"resource,omitempty"`
	Pointer  string `json:"pointer,omitempty"`
	Message  string `json:"message"`
	Hint     string `json:"hint,omitempty"`
	// HTTPStatus overrides the registry status, for codes chosen by scripts.
	HTTPStatus int `json:"-"`
	// Rejected marks errors raised by ctx.reject: business refusals, never
	// definition errors.
	Rejected bool `json:"-"`
	// Topic overrides the guide topic of the code.
	Topic string `json:"-"`
}

// HintText is the hint followed by the guide topic of the code, so every
// output (problem+json, shaped errors, reports, MCP) teaches the same way.
// Business refusals (ctx.reject) keep the author's hint as is.
func (e *Error) HintText() string {
	topic := Registry[e.Code].Topic
	if e.Topic != "" {
		topic = e.Topic
	}
	if e.Rejected || topic == "" {
		return e.Hint
	}
	see := "see: caddy resource guide " + topic
	if e.Hint == "" {
		return see
	}
	return e.Hint + " " + see
}

func (e *Error) MarshalJSON() ([]byte, error) {
	type plain Error
	c := plain(*e)
	c.Hint = e.HintText()
	return json.Marshal(c)
}

func (e *Error) Error() string {
	b, _ := json.Marshal(e)
	return string(b)
}

func (e *Error) Status() int {
	if e.HTTPStatus != 0 {
		return e.HTTPStatus
	}
	if e.Rejected {
		return http.StatusUnprocessableEntity
	}
	if info, ok := Registry[e.Code]; ok {
		return info.Status
	}
	return http.StatusInternalServerError
}

// List is a validation report. Its Error() is the same JSON document that
// `caddy resource validate` prints, so Caddy surfaces it verbatim when
// provisioning fails.
type List []*Error

func (l List) Sort() {
	slices.SortStableFunc(l, func(a, b *Error) int { return strings.Compare(a.Pointer, b.Pointer) })
}

func (l List) Error() string {
	b, _ := json.Marshal(NewReport(l, nil))
	return string(b)
}

type Report struct {
	Valid    bool `json:"valid"`
	Errors   List `json:"errors"`
	Warnings List `json:"warnings"`
}

func NewReport(errs, warnings List) Report {
	if errs == nil {
		errs = List{}
	}
	if warnings == nil {
		warnings = List{}
	}
	return Report{Valid: len(errs) == 0, Errors: errs, Warnings: warnings}
}

func As(err error) (*Error, bool) {
	var e *Error
	if errors.As(err, &e) {
		return e, true
	}
	return nil, false
}

type PgContext struct {
	Resource string
	Pointer  string
	// SQL is the statement that was sent, SourceOffset the byte offset where
	// the definition's own query starts inside it. Both are used to translate
	// Postgres error positions back to the user's query.
	SQL          string
	SourceOffset int
	// Body makes constraint errors point at the request body property named
	// like the offending column.
	Body bool
}

func FromPg(err error, ctx PgContext) *Error {
	var pgErr *pgconn.PgError
	if !errors.As(err, &pgErr) {
		if isConnectionError(err) {
			return &Error{Code: DatabaseUnreachable, Resource: ctx.Resource, Pointer: ctx.Pointer, Message: err.Error(), Hint: "Check that PostgreSQL is running and that the dsn (user, password, host, port, database) is correct."}
		}
		return &Error{Code: QueryFailed, Resource: ctx.Resource, Pointer: ctx.Pointer, Message: err.Error()}
	}
	e := &Error{Resource: ctx.Resource, Pointer: ctx.Pointer, Message: pgErr.Message + locate(pgErr, ctx)}
	if pgErr.Detail != "" {
		e.Message += " (" + pgErr.Detail + ")"
	}
	switch pgErr.Code {
	case "42P01":
		e.Code = UnknownTable
		e.Hint = "The table does not exist in this database. Check the spelling and the schema (use schema.table for a non-public schema)."
	case "42703":
		e.Code = UnknownColumn
		e.Hint = "The column does not exist. Check the column name and the table alias it is qualified with. Unquoted identifiers are folded to lower case by PostgreSQL."
	case "42702":
		e.Code = DuplicateColumn
		e.Hint = "A column name is ambiguous. Qualify it with its table alias (b.id) and give every selected column a unique name (a.id AS author_id)."
	case "42601":
		e.Code = QuerySyntaxError
		e.Hint = "Fix the SQL syntax. Write a single SELECT statement, without a trailing semicolon, without LIMIT/OFFSET (pagination is added for you)."
	case "42501":
		e.Code = PermissionDenied
		e.Hint = "The database user is not allowed to read this object. Read only from tables the dsn user has SELECT on."
	case "42P18", "42P08":
		e.Code = QueryFailed
		e.Hint = "PostgreSQL cannot infer the type of an interpolated value. Add an explicit cast after the interpolation, e.g. ${ctx.request.query(\"year\")}::int or ${value}::text."
	case "42883":
		e.Code = QueryFailed
		e.Hint = "No function or operator matches the argument types. Interpolated values from the request are strings: cast them, e.g. ${value}::int."
	case "23505":
		e.Code = Conflict
		e.Hint = "A row with this value already exists. " + keyHint(pgErr)
		bodyPointer(e, pgErr, ctx)
	case "23503":
		e.Code = InvalidReference
		e.Hint = fmt.Sprintf("The referenced row does not exist (constraint %s). Use the identifier of an existing row.", pgErr.ConstraintName)
		if pgErr.TableName != "" && strings.Contains(pgErr.Detail, "is still referenced") {
			e.Hint = fmt.Sprintf("Other rows of table %s still reference this one; delete or update them first.", pgErr.TableName)
		}
		bodyPointer(e, pgErr, ctx)
	case "23502":
		e.Code = ConstraintViolation
		e.Hint = fmt.Sprintf("Column %q cannot be NULL and has no default: send a value for it.", pgErr.ColumnName)
		bodyPointer(e, pgErr, ctx)
	case "23514", "23P01":
		e.Code = ConstraintViolation
		e.Hint = fmt.Sprintf("The value violates constraint %s.", pgErr.ConstraintName)
		bodyPointer(e, pgErr, ctx)
	case "22001":
		e.Code = ConstraintViolation
		e.Hint = "A value is too long for its column."
	case "22P02", "22007", "22008", "22003":
		e.Code = InvalidParameter
		e.Hint = "A value could not be converted to the column type. Validate or cast request values before comparing them."
	default:
		e.Code = QueryFailed
		e.Hint = fmt.Sprintf("PostgreSQL error %s. Run the query manually to investigate.", pgErr.Code)
	}
	return e
}

func keyHint(pgErr *pgconn.PgError) string {
	if pgErr.Detail != "" {
		return pgErr.Detail
	}
	return "Constraint " + pgErr.ConstraintName + "."
}

// bodyPointer points the error at the body property named like the column,
// read from ColumnName or from a "Key (col)=(...)" detail.
func bodyPointer(e *Error, pgErr *pgconn.PgError, ctx PgContext) {
	if !ctx.Body {
		return
	}
	col := pgErr.ColumnName
	if col == "" {
		if i := strings.Index(pgErr.Detail, "Key ("); i >= 0 {
			rest := pgErr.Detail[i+5:]
			if j := strings.Index(rest, ")"); j >= 0 && !strings.Contains(rest[:j], ",") {
				col = rest[:j]
			}
		}
	}
	if col != "" {
		e.Pointer = "/" + col
	}
}

func locate(pgErr *pgconn.PgError, ctx PgContext) string {
	if pgErr.Position <= 0 || ctx.SQL == "" {
		return ""
	}
	pos := int(pgErr.Position) - 1
	runes := []rune(ctx.SQL)
	if pos >= len(runes) {
		return ""
	}
	offset := len([]rune(ctx.SQL[:min(ctx.SourceOffset, len(ctx.SQL))]))
	if pos < offset {
		return ""
	}
	src := runes[offset:]
	rel := pos - offset
	line, col := 1, 1
	for _, r := range src[:min(rel, len(src))] {
		if r == '\n' {
			line++
			col = 1
		} else {
			col++
		}
	}
	lines := strings.Split(string(src), "\n")
	snippet := ""
	if line-1 < len(lines) {
		snippet = strings.TrimSpace(lines[line-1])
	}
	return fmt.Sprintf(" at line %d, column %d of the query: %q", line, col, snippet)
}

func isConnectionError(err error) bool {
	var connErr *pgconn.ConnectError
	if errors.As(err, &connErr) {
		return true
	}
	msg := err.Error()
	for _, s := range []string{"failed to connect", "connection refused", "dial error", "no such host", "password authentication failed", "database \"", "cannot parse"} {
		if strings.Contains(msg, s) {
			return true
		}
	}
	return false
}

// Suggest returns the closest candidate to s, or "" when nothing is close
// enough to be a plausible typo.
func Suggest(s string, candidates []string) string {
	best, bestDist := "", -1
	for _, c := range candidates {
		d := levenshtein(strings.ToLower(s), strings.ToLower(c))
		if bestDist == -1 || d < bestDist {
			best, bestDist = c, d
		}
	}
	if bestDist == -1 || bestDist > max(2, len(s)/3) {
		return ""
	}
	return best
}

func levenshtein(a, b string) int {
	ra, rb := []rune(a), []rune(b)
	prev := make([]int, len(rb)+1)
	cur := make([]int, len(rb)+1)
	for j := range prev {
		prev[j] = j
	}
	for i := 1; i <= len(ra); i++ {
		cur[0] = i
		for j := 1; j <= len(rb); j++ {
			cost := 1
			if ra[i-1] == rb[j-1] {
				cost = 0
			}
			cur[j] = min(prev[j]+1, cur[j-1]+1, prev[j-1]+cost)
		}
		prev, cur = cur, prev
	}
	return prev[len(rb)]
}

func AllCodes() []Code {
	codes := make([]Code, 0, len(Registry))
	for c := range Registry {
		codes = append(codes, c)
	}
	slices.Sort(codes)
	return codes
}

func (r Report) Error() string {
	b, _ := json.Marshal(r)
	return string(b)
}
