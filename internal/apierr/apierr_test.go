package apierr

import (
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"testing"

	"github.com/jackc/pgx/v5/pgconn"
)

func TestErrorJSONShape(t *testing.T) {
	e := &Error{Code: UnknownColumn, Resource: "Book", Pointer: "/properties/author", Message: `column "author" not returned by query`, Hint: "alias it"}
	b, _ := json.Marshal(e)
	want := `{"code":"unknown_column","resource":"Book","pointer":"/properties/author","message":"column \"author\" not returned by query","hint":"alias it see: caddy resource guide queries"}`
	if string(b) != want {
		t.Fatalf("got %s", b)
	}
}

func TestHintEndsWithTheGuideTopic(t *testing.T) {
	cases := []struct {
		e    *Error
		want string
	}{
		{&Error{Code: InvalidParameter, Hint: "Send an integer."}, "Send an integer. see: caddy resource guide parameters"},
		{&Error{Code: UnknownProperty}, "see: caddy resource guide commands"},
		{&Error{Code: Rejected, Rejected: true, Hint: "Create the author first."}, "Create the author first."},
		{&Error{Code: "out_of_stock", HTTPStatus: 409, Hint: "h"}, "h"},
		{&Error{Code: InvalidValue, Hint: "h", Topic: "operations"}, "h see: caddy resource guide operations"},
	}
	for _, c := range cases {
		if got := c.e.HintText(); got != c.want {
			t.Errorf("%s: got %q, want %q", c.e.Code, got, c.want)
		}
	}
}

func TestListErrorStringIsReportJSON(t *testing.T) {
	l := List{{Code: MissingIdentifier, Resource: "Book", Pointer: "/properties", Message: "m", Hint: "h"}}
	var report struct {
		Valid  bool     `json:"valid"`
		Errors []*Error `json:"errors"`
	}
	if err := json.Unmarshal([]byte(l.Error()), &report); err != nil {
		t.Fatalf("not json: %v: %s", err, l.Error())
	}
	if report.Valid || len(report.Errors) != 1 || report.Errors[0].Code != MissingIdentifier {
		t.Fatalf("unexpected report %+v", report)
	}
}

func TestEveryCodeIsDocumented(t *testing.T) {
	for _, c := range AllCodes() {
		info, ok := Registry[c]
		if !ok || info.Summary == "" || info.Status == 0 {
			t.Errorf("code %s has no registry entry", c)
		}
	}
}

func TestFromPgMapsUndefinedColumn(t *testing.T) {
	pgErr := &pgconn.PgError{Code: "42703", Message: `column b.titl does not exist`}
	e := FromPg(fmt.Errorf("wrap: %w", pgErr), PgContext{Resource: "Book", Pointer: "/query"})
	if e.Code != UnknownColumn || e.Resource != "Book" || e.Pointer != "/query" {
		t.Fatalf("unexpected %+v", e)
	}
	if !strings.Contains(e.Message, "titl") || e.Hint == "" {
		t.Fatalf("message/hint missing: %+v", e)
	}
}

func TestFromPgMapsSyntaxErrorWithPosition(t *testing.T) {
	sql := "SELECT * FROM (\nSELECT id\nFORM book\n) r LIMIT 0"
	pgErr := &pgconn.PgError{Code: "42601", Message: `syntax error at or near "book"`, Position: int32(strings.Index(sql, "book") + 1)}
	e := FromPg(pgErr, PgContext{Resource: "Book", Pointer: "/query", SQL: sql, SourceOffset: len("SELECT * FROM (\n")})
	if e.Code != QuerySyntaxError {
		t.Fatalf("unexpected code %s", e.Code)
	}
	if !strings.Contains(e.Message, "line 2") {
		t.Fatalf("expected location relative to the user query, got %q", e.Message)
	}
}

func TestFromPgMapsIndeterminateParameter(t *testing.T) {
	e := FromPg(&pgconn.PgError{Code: "42P18", Message: "could not determine data type of parameter $1"}, PgContext{})
	if e.Code != QueryFailed || !strings.Contains(e.Hint, "::") {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestFromPgMapsConnectionFailure(t *testing.T) {
	e := FromPg(errors.New("failed to connect to `host=localhost`: dial error"), PgContext{})
	if e.Code != DatabaseUnreachable {
		t.Fatalf("unexpected %+v", e)
	}
}

func TestSuggest(t *testing.T) {
	if got := Suggest("itemPerPage", []string{"name", "itemsPerPage", "path"}); got != "itemsPerPage" {
		t.Fatalf("got %q", got)
	}
	if got := Suggest("zzz", []string{"name", "path"}); got != "" {
		t.Fatalf("got %q", got)
	}
}

func TestReportAlwaysListsWarnings(t *testing.T) {
	b, _ := json.Marshal(NewReport(nil, nil))
	if string(b) != `{"valid":true,"errors":[],"warnings":[]}` {
		t.Fatalf("got %s", b)
	}
}
