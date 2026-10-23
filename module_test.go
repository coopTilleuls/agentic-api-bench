package llmapi

import (
	"reflect"
	"strings"
	"testing"

	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"go.uber.org/zap"
	"go.uber.org/zap/zaptest/observer"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

func parse(t *testing.T, src string) (*Handler, error) {
	t.Helper()
	var h Handler
	err := h.UnmarshalCaddyfile(caddyfile.NewTestDispenser(src))
	return &h, err
}

func TestCaddyfileOutputOptions(t *testing.T) {
	h, err := parse(t, `resource {
		dir resources
		dsn x
		formats json csv
		envelope {
			items items
			total total
			page page
			itemsPerPage itemsPerPage
			pages pages
		}
		errors {
			error message
		}
	}`)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(h.Formats, []string{"json", "csv"}) {
		t.Fatalf("formats %v", h.Formats)
	}
	if !reflect.DeepEqual(h.Envelope, map[string]string{"items": "items", "total": "total", "page": "page", "itemsPerPage": "itemsPerPage", "pages": "pages"}) {
		t.Fatalf("envelope %v", h.Envelope)
	}
	if !reflect.DeepEqual(h.Errors, resource.ErrorShape{"error": "message"}) {
		t.Fatalf("errors %v", h.Errors)
	}
	h, err = parse(t, "resource {\n\terror_details off\n}")
	if err != nil || h.ErrorDetails == nil || *h.ErrorDetails {
		t.Fatalf("error_details off: %v %v", h.ErrorDetails, err)
	}
	h, err = parse(t, "resource {\n\terrors problem\n}")
	if err != nil || h.Errors == nil || len(h.Errors) != 0 {
		t.Fatalf("errors problem: %v %v", h.Errors, err)
	}
}

func TestCaddyfileOutputOptionErrors(t *testing.T) {
	for src, want := range map[string]string{
		"resource {\n\tformats xml\n}":                      "jsonld, json, csv",
		"resource {\n\tenvelope {\n\t\tcount total\n\t}\n}": "items, total, page, itemsPerPage, pages",
		"resource {\n\terrors {\n\t\terror text\n\t}\n}":    "message, code, status, pointer, hint, resource",
		"resource {\n\terrors html\n}":                      "problem",
		"resource {\n\tbogus 1\n}":                          "envelope",
	} {
		if _, err := parse(t, src); err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%q: got %v, want mention of %q", src, err, want)
		}
	}
}

func TestReloadLogsWarnings(t *testing.T) {
	core, logs := observer.New(zap.InfoLevel)
	warn := &apierr.Error{Code: apierr.DeprecatedHeaderPrefix, Resource: "Purchase", Pointer: "/parameters/X-Customer-Id", Message: "m", Hint: "h"}
	logReport(zap.New(core), "resources", apierr.NewReport(nil, apierr.List{warn}))
	entries := logs.FilterLevelExact(zap.WarnLevel).All()
	if len(entries) != 1 || !strings.Contains(entries[0].ContextMap()["report"].(string), "deprecated_header_prefix") {
		t.Fatalf("got %v", logs.All())
	}
}
