package guide_test

import (
	"regexp"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/app"
	"github.com/coopTilleuls/agentic-api-bench/internal/guide"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

func TestCoreExampleIsAValidDefinition(t *testing.T) {
	core, err := guide.Render("", guide.Options{})
	if err != nil {
		t.Fatal(err)
	}
	m := regexp.MustCompile("(?s)```json\n(.*?)```").FindStringSubmatch(core)
	if m == nil {
		t.Fatal("core guide has no json example")
	}
	_, errs, _ := resource.LoadInline("Book.json", []byte(m[1]), nil, app.NewEngine())
	if len(errs) > 0 {
		t.Fatal(errs.Error())
	}
}
