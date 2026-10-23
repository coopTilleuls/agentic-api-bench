package guide

import (
	"encoding/json"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"testing"
	"unicode/utf8"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

var withMCP, withoutMCP = Options{MCP: true}, Options{MCP: false}

func render(t *testing.T, name string, opts Options) string {
	t.Helper()
	text, err := Render(name, opts)
	if err != nil {
		t.Fatalf("Render(%q): %v", name, err)
	}
	return text
}

func topicNames(opts Options) []string {
	var names []string
	for _, tp := range Topics(opts) {
		names = append(names, tp.Name)
	}
	return names
}

func TestCoreGuideIsShortAndIndexesEveryTopic(t *testing.T) {
	for _, opts := range []Options{withMCP, withoutMCP} {
		core := render(t, "", opts)
		if n := strings.Count(core, "\n"); n > 120 {
			t.Errorf("core guide has %d lines, want <= 120", n)
		}
		for _, tp := range Topics(opts) {
			if tp.Summary == "" || !strings.Contains(core, "`"+tp.Name+"`") || !strings.Contains(core, tp.Summary) {
				t.Errorf("core index misses topic %s", tp.Name)
			}
		}
		for _, s := range []string{"caddy run", "caddy resource validate", "caddy resource guide <topic>", "caddy resource introspect", "caddy resource sql", "caddy resource request", "caddy resource eval", "curl"} {
			if !strings.Contains(core, s) {
				t.Errorf("core guide misses %q", s)
			}
		}
	}
}

func TestTopics(t *testing.T) {
	want := []string{"definitions", "queries", "parameters", "commands", "operations", "formats", "scripts", "server", "errors", "mcp", "schema"}
	if got := topicNames(withMCP); !slices.Equal(got, want) {
		t.Fatalf("topics: %v", got)
	}
	if got := topicNames(withoutMCP); slices.Contains(got, "mcp") || len(got) != len(want)-1 {
		t.Fatalf("topics without MCP: %v", got)
	}
}

func TestEveryTopicRenders(t *testing.T) {
	for _, name := range topicNames(withMCP) {
		text := render(t, name, withMCP)
		if !strings.HasPrefix(text, "# ") || strings.Count(text, "\n") < 5 {
			t.Errorf("topic %s: %q", name, text)
		}
	}
}

func TestAllIsAnUnknownTopic(t *testing.T) {
	_, err := Render("all", withMCP)
	if err == nil || !strings.Contains(err.Error(), "unknown guide topic") {
		t.Fatalf("err: %v", err)
	}
	for _, tp := range Topics(withMCP) {
		if !strings.Contains(err.Error(), tp.Name+": ") {
			t.Errorf("error does not describe topic %s: %v", tp.Name, err)
		}
		if !strings.Contains(err.Error(), tp.Summary) {
			t.Errorf("error does not carry the summary of %s: %v", tp.Name, err)
		}
	}
	if strings.Contains(err.Error(), ", all") {
		t.Errorf("error still offers all: %v", err)
	}
}

func TestGuideListsEveryErrorCode(t *testing.T) {
	text := render(t, "errors", withMCP)
	for _, c := range apierr.AllCodes() {
		if !strings.Contains(text, "`"+string(c)+"`") {
			t.Errorf("errors does not document %s", c)
		}
	}
}

func TestEveryErrorCodeMapsToATopic(t *testing.T) {
	topics := topicNames(withoutMCP)
	for _, c := range apierr.AllCodes() {
		if tp := apierr.Registry[c].Topic; !slices.Contains(topics, tp) {
			t.Errorf("%s maps to topic %q, not one of %v", c, tp, topics)
		}
	}
}

func TestGuideEmbedsSchemaAndCommands(t *testing.T) {
	text := renderAll(t, withMCP)
	for _, s := range []string{"caddy resource validate", "caddy resource init", "caddy resource guide", "ctx.sql`", "ctx.request.header(", "defineResource", "json_agg", "ctx.phase", "strictly_after", "Content-Disposition", "row_count"} {
		if !strings.Contains(text, s) {
			t.Errorf("guide misses %q", s)
		}
	}
}

func TestGuideMCPIsOptional(t *testing.T) {
	if !strings.Contains(renderAll(t, withMCP), "/mcp") {
		t.Fatal("MCP guide should mention /mcp")
	}
	for _, name := range append([]string{""}, topicNames(withoutMCP)...) {
		if strings.Contains(strings.ToLower(render(t, name, withoutMCP)), "mcp") {
			t.Errorf("guide %q without MCP must not mention it", name)
		}
	}
}

func renderAll(t *testing.T, opts Options) string {
	t.Helper()
	parts := []string{render(t, "", opts)}
	for _, name := range topicNames(opts) {
		parts = append(parts, render(t, name, opts))
	}
	return strings.Join(parts, "\n")
}

func TestTopicBudgets(t *testing.T) {
	const (
		coreMax  = 6000
		topicMax = 5000
		totalMax = 40000
	)
	for _, opts := range []Options{withMCP, withoutMCP} {
		core := utf8.RuneCountInString(render(t, "", opts))
		total := core
		if core > coreMax {
			t.Errorf("mcp=%v core: %d chars, budget %d", opts.MCP, core, coreMax)
		}
		for _, name := range topicNames(opts) {
			n := utf8.RuneCountInString(render(t, name, opts))
			total += n
			if n > topicMax {
				t.Errorf("mcp=%v topic %s: %d chars, budget %d", opts.MCP, name, n, topicMax)
			}
		}
		if total > totalMax {
			t.Errorf("mcp=%v all topics: %d chars, budget %d", opts.MCP, total, totalMax)
		}
	}
}

var guideHint = regexp.MustCompile(`see: caddy resource guide ([a-z]+)`)

func TestHintsReferenceRenderableTopics(t *testing.T) {
	found := map[string]string{}
	err := filepath.WalkDir(filepath.Join("..", ".."), func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() && (d.Name() == ".git" || d.Name() == "node_modules" || d.Name() == "vendor") {
			return filepath.SkipDir
		}
		if d.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return nil
		}
		src, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		for _, m := range guideHint.FindAllStringSubmatch(string(src), -1) {
			found[m[1]] = path
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	for _, info := range apierr.Registry {
		found[info.Topic] = "internal/apierr"
	}
	if len(found) < 5 {
		t.Fatalf("hint scan looks broken: %v", found)
	}
	for topic, where := range found {
		if _, err := Render(topic, withMCP); err != nil {
			t.Errorf("%s refers to guide topic %q: %v", where, topic, err)
		}
	}
}

func TestFastWorkflowAndNewFeaturesAreDocumented(t *testing.T) {
	core := render(t, "", withMCP)
	for _, s := range []string{"caddy resource introspect", "caddy resource sql", "caddy resource request", "caddy resource eval", "caddy resource validate", "--commit"} {
		if !strings.Contains(core, s) {
			t.Errorf("core misses %q", s)
		}
	}
	if strings.Index(core, "caddy resource introspect") > len(core)/3 {
		t.Error("the fast workflow must come near the top of core")
	}
	commands := render(t, "commands", withMCP)
	for _, s := range []string{"introspect", "caddy resource sql", "caddy resource request METHOD PATH", "-H", "-d", "caddy resource eval", "ctx.crypto", "ctx.time", "ctx.env", "--commit", "rolled back"} {
		if !strings.Contains(commands, s) {
			t.Errorf("commands misses %q", s)
		}
	}
	scripts := render(t, "scripts", withMCP)
	for _, s := range []string{"ctx.request.body", "ctx.request.json()", "input: {}"} {
		if !strings.Contains(scripts, s) {
			t.Errorf("scripts misses %q", s)
		}
	}
	definitions := render(t, "definitions", withMCP)
	for _, s := range []string{"order-data", "whitespace", "`_`", "column"} {
		if !strings.Contains(definitions, s) {
			t.Errorf("definitions misses %q", s)
		}
	}
}

var droppedIdentifiers = map[string]string{
	"hex_decode":    "snake_case example, covered by the rule that Starlark takes snake_case names",
	"base64_encode": "snake_case example, covered by the rule that Starlark takes snake_case names",
}

func TestEveryDocumentedIdentifierSurvives(t *testing.T) {
	raw, err := os.ReadFile("testdata/identifiers.txt")
	if err != nil {
		t.Fatal(err)
	}
	all := renderAll(t, withMCP)
	for _, id := range strings.Split(strings.TrimSpace(string(raw)), "\n") {
		if _, dropped := droppedIdentifiers[id]; dropped {
			continue
		}
		if !strings.Contains(all, id) {
			t.Errorf("identifier %q is no longer documented", id)
		}
	}
}

func TestSchemaOutlineCoversEverySchemaKey(t *testing.T) {
	var doc struct {
		Properties map[string]any            `json:"properties"`
		Defs       map[string]map[string]any `json:"$defs"`
	}
	if err := json.Unmarshal(resource.SchemaJSON, &doc); err != nil {
		t.Fatal(err)
	}
	outline := render(t, "schema", withMCP)
	for key := range doc.Properties {
		if key != "$schema" && key != "description" && !strings.Contains(outline, key+": ") && !strings.Contains(outline, key+"*: ") {
			t.Errorf("schema outline misses top-level key %s", key)
		}
	}
	for name, def := range doc.Defs {
		if !strings.Contains(outline, "<"+name+">\n") {
			t.Errorf("schema outline misses section %s", name)
		}
		for key := range def["properties"].(map[string]any) {
			if key != "description" && !strings.Contains(outline, key) {
				t.Errorf("schema outline misses %s.%s", name, key)
			}
		}
	}
}
