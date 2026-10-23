package llmapi

import (
	"bytes"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/caddyserver/caddy/v2"
	"github.com/spf13/cobra"
)

func fakeCaddyRoot(runErr error) *cobra.Command {
	root := &cobra.Command{Use: "caddy", SilenceUsage: true, SilenceErrors: true}
	root.AddCommand(&cobra.Command{
		Use: "version",
		RunE: func(cmd *cobra.Command, _ []string) error {
			cmd.Println("v2.11.7 h1:abc")
			return nil
		},
	})
	list := &cobra.Command{
		Use: "list-modules",
		RunE: func(cmd *cobra.Command, _ []string) error {
			cmd.Println("http.handlers.resource\n\n  Non-standard modules: 1")
			return nil
		},
	}
	list.Flags().Bool("json", false, "")
	root.AddCommand(list)
	for _, name := range []string{"run", "validate", "adapt", "reload"} {
		c := &cobra.Command{
			Use:  name,
			RunE: func(*cobra.Command, []string) error { return runErr },
		}
		c.Flags().String("config", "", "")
		c.Flags().Bool("resume", false, "")
		root.AddCommand(c)
	}
	decorateRoot(root)
	return root
}

func execFake(t *testing.T, root *cobra.Command, args ...string) (string, error) {
	t.Helper()
	var out bytes.Buffer
	root.SetOut(&out)
	root.SetErr(&out)
	root.SetArgs(args)
	err := root.Execute()
	return out.String(), err
}

func TestVersionPrintsFrameworkLine(t *testing.T) {
	out, err := execFake(t, fakeCaddyRoot(nil), "version")
	if err != nil {
		t.Fatal(err)
	}
	lines := strings.Split(strings.TrimSpace(out), "\n")
	if len(lines) != 2 || lines[0] != "v2.11.7 h1:abc" {
		t.Fatalf("version output: %q", out)
	}
	for _, want := range []string{"with resource:", "REST API framework for PostgreSQL", "caddy resource guide"} {
		if !strings.Contains(lines[1], want) {
			t.Errorf("line %q lacks %q", lines[1], want)
		}
	}
}

func TestListModulesFooterNamesFramework(t *testing.T) {
	out, _ := execFake(t, fakeCaddyRoot(nil), "list-modules")
	if !strings.Contains(out, "Non-standard modules: 1") || !strings.Contains(out, "http.handlers.resource  declarative REST API framework for PostgreSQL") || !strings.Contains(out, "caddy resource guide") {
		t.Fatalf("%s", out)
	}
	out, _ = execFake(t, fakeCaddyRoot(nil), "list-modules", "--json")
	if strings.Contains(out, "caddy resource guide") {
		t.Fatalf("--json must stay untouched: %s", out)
	}
}

func chdirEmpty(t *testing.T) {
	t.Helper()
	old, _ := os.Getwd()
	if err := os.Chdir(t.TempDir()); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.Chdir(old) })
}

func TestRunWithoutCaddyfileHintsAtInit(t *testing.T) {
	chdirEmpty(t)
	out, err := execFake(t, fakeCaddyRoot(nil), "run")
	if err != nil || !strings.Contains(out, "caddy resource init") || !strings.Contains(out, "caddy resource guide") {
		t.Fatalf("err %v: %s", err, out)
	}
	out, _ = execFake(t, fakeCaddyRoot(nil), "run", "--config", "caddy.json")
	if strings.Contains(out, "caddy resource init") {
		t.Fatalf("explicit --config must not hint: %s", out)
	}
	if err := os.WriteFile("Caddyfile", []byte(":8080\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	out, _ = execFake(t, fakeCaddyRoot(nil), "run")
	if strings.Contains(out, "caddy resource init") {
		t.Fatalf("Caddyfile present must not hint: %s", out)
	}
}

func TestNoConfigErrorsGetHint(t *testing.T) {
	for _, name := range []string{"validate", "adapt", "reload"} {
		root := fakeCaddyRoot(errors.New("input file required when there is no Caddyfile in current directory (use --config flag)"))
		out, err := execFake(t, root, name)
		if err == nil || !strings.Contains(out, "caddy resource init") {
			t.Errorf("%s: err %v out %q", name, err, out)
		}
	}
	root := fakeCaddyRoot(errors.New("no config file to load"))
	if out, _ := execFake(t, root, "reload"); !strings.Contains(out, "caddy resource guide") {
		t.Errorf("reload: %q", out)
	}
	root = fakeCaddyRoot(errors.New("loading config: boom"))
	if out, _ := execFake(t, root, "validate"); strings.Contains(out, "caddy resource") {
		t.Errorf("unrelated error must not hint: %q", out)
	}
}

func TestRootHelpLeadsWithFramework(t *testing.T) {
	first := strings.SplitN(caddy.CustomLongDescription, "\n", 4)
	head := strings.Join(first[:3], "\n")
	if !strings.Contains(head, "REST API framework") || !strings.Contains(head, "PostgreSQL") || !strings.Contains(caddy.CustomLongDescription, "caddy resource guide") {
		t.Fatalf("long description head: %q", head)
	}
}

func TestResourceShortDescribesFramework(t *testing.T) {
	if !strings.Contains(resourceShort, "REST API framework") || !strings.Contains(resourceShort, "caddy resource guide") {
		t.Fatalf("short: %q", resourceShort)
	}
}
