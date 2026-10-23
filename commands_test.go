package llmapi

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/spf13/cobra"

	_ "github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	_ "github.com/caddyserver/caddy/v2/modules/standard"
)

func TestDirFromCaddyfile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "Caddyfile")
	if got := dirFromCaddyfile(path); got != "resources" {
		t.Fatalf("missing Caddyfile: %q", got)
	}
	_ = os.WriteFile(path, []byte(":8089 {\n\tresource api {\n\t\tdsn x\n\t}\n}\n"), 0o644)
	if got := dirFromCaddyfile(path); got != "api" {
		t.Fatalf("got %q", got)
	}
	_ = os.WriteFile(path, []byte(":8089 {\n\tresource {\n\t\tdsn x\n\t}\n}\n"), 0o644)
	if got := dirFromCaddyfile(path); got != "resources" {
		t.Fatalf("default: %q", got)
	}
}

func runResource(t *testing.T, args ...string) (string, error) {
	t.Helper()
	cmd := &cobra.Command{Use: "resource", Long: resourceLong}
	configureResource(cmd)
	var out bytes.Buffer
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetArgs(args)
	err := cmd.Execute()
	if err != nil {
		out.WriteString(err.Error())
	}
	return out.String(), err
}

func TestResourceHelpMentionsGuide(t *testing.T) {
	for _, args := range [][]string{{"--help"}, {}} {
		out, _ := runResource(t, args...)
		if !strings.Contains(out, "caddy resource guide <topic>") || !strings.Contains(out, "guide") {
			t.Errorf("%v: %s", args, out)
		}
	}
}

func TestUnknownResourceCommandPointsAtGuide(t *testing.T) {
	out, err := runResource(t, "serve")
	if err == nil || !strings.Contains(out, `unknown command "serve"`) || !strings.Contains(out, "caddy resource guide") {
		t.Fatalf("err %v: %s", err, out)
	}
}

func TestGuideCommandTopics(t *testing.T) {
	core, err := runResource(t, "guide")
	if err != nil || strings.Count(core, "\n") > 120 || !strings.Contains(core, "caddy resource guide <topic>") {
		t.Fatalf("core (%v): %d lines", err, strings.Count(core, "\n"))
	}
	if out, err := runResource(t, "guide", "parameters"); err != nil || !strings.HasPrefix(out, "# ") || !strings.Contains(out, "ipartial") {
		t.Fatalf("parameters (%v): %.200s", err, out)
	}
	if out, err := runResource(t, "guide", "nope"); err == nil || !strings.Contains(out, "parameters") {
		t.Fatalf("unknown topic (%v): %s", err, out)
	}
}
