// Package app wires definitions, the database, the HTTP API and MCP together.
// It is shared by the Caddy handler and the `caddy resource` commands.
package app

import (
	"context"
	"crypto/sha256"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/guide"
	"github.com/coopTilleuls/agentic-api-bench/internal/httpapi"
	"github.com/coopTilleuls/agentic-api-bench/internal/mcp"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
)

const ScriptTimeout = 10 * time.Second

const DefaultDir = "resources"

// PollInterval is how often the definitions directory is checked for
// changes; see Watch.
const PollInterval = 500 * time.Millisecond

// MCPDefault is the default of the `mcp` option. LLMAPI_MCP=off flips it so
// an evaluation can hide MCP everywhere, including from the guide.
func MCPDefault() bool {
	return !strings.EqualFold(os.Getenv("LLMAPI_MCP"), "off")
}

func DefaultDSN() string {
	return os.Getenv("DATABASE_URL")
}

func NewEngine() script.Engine {
	return script.NewEngine(ScriptTimeout)
}

func OpenPool(ctx context.Context, dsn string) (*pgxpool.Pool, *apierr.Error) {
	cfg, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		return nil, &apierr.Error{Code: apierr.InvalidConfig, Message: "invalid dsn: " + err.Error(), Hint: "Use postgres://user:password@host:5432/dbname (URL) or \"host=... user=... dbname=...\"."}
	}
	cfg.MaxConns = 8
	pool, err := pgxpool.NewWithConfig(ctx, cfg)
	if err != nil {
		return nil, apierr.FromPg(err, apierr.PgContext{})
	}
	return pool, nil
}

// Validate loads dir and, when pool is non-nil, checks it against the
// database. Definitions failing a database check are moved to set.Broken.
func Validate(ctx context.Context, dir string, pool *pgxpool.Pool, engine script.Engine) (*resource.Set, apierr.Report) {
	set, errs, warns := resource.Load(dir, engine)
	return set, finish(ctx, set, errs, warns, pool)
}

func finish(ctx context.Context, set *resource.Set, errs, warns apierr.List, pool *pgxpool.Pool) apierr.Report {
	if pool == nil {
		warns = append(warns, &apierr.Error{Code: apierr.DatabaseChecksSkipped, Message: "no dsn given: tables, columns and types were not checked", Hint: "Pass --dsn postgres://user:password@host:5432/dbname (or set DATABASE_URL) to validate against the database."})
		return apierr.NewReport(errs, warns)
	}
	dbErrs := resource.Check(ctx, &resource.Runtime{Pool: pool, Set: set}, set)
	set.Demote(dbErrs)
	return apierr.NewReport(append(errs, dbErrs...), warns)
}

type state struct {
	set         *resource.Set
	server      *httpapi.Server
	report      apierr.Report
	fingerprint string
}

// App serves whatever state the definitions are in: valid resources work,
// broken ones answer their validation errors, /mcp is always up, and the
// directory is reloaded when it changes.
type App struct {
	Dir string
	// DirFunc, when set, is asked for the directory before every reload
	// (`caddy resource mcp --config` follows the Caddyfile's dir option).
	DirFunc func() string
	Engine  script.Engine
	Pool    *pgxpool.Pool
	MCP     *mcp.Server
	OnError func(r *http.Request, err *apierr.Error)
	// OnReload is called after each reload with the new report.
	OnReload func(apierr.Report)
	// Output is the site-level output configuration (Caddyfile).
	Output httpapi.Output

	current  atomic.Pointer[state]
	reloadMu sync.Mutex
	serving  bool
}

// Start is New followed by a first Reload.
func Start(ctx context.Context, dir, dsn string, enableMCP bool) (*App, error) {
	a, err := New(ctx, dir, dsn, enableMCP)
	if err != nil {
		return nil, err
	}
	a.Reload(ctx)
	return a, nil
}

// New fails only on configuration errors (no or unparsable dsn); invalid
// definitions and an unreachable database are served as errors once
// Reload has run.
func New(ctx context.Context, dir, dsn string, enableMCP bool) (*App, error) {
	if dsn == "" {
		return nil, &apierr.Error{Code: apierr.InvalidConfig, Message: "no dsn configured", Hint: "Add `dsn postgres://user:password@host:5432/dbname` inside the resource block, or set DATABASE_URL."}
	}
	pool, perr := OpenPool(ctx, dsn)
	if perr != nil {
		return nil, perr
	}
	a := &App{Dir: dir, Engine: NewEngine(), Pool: pool, serving: true}
	if enableMCP {
		a.MCP = &mcp.Server{Backend: a, PerResourceTools: true}
	}
	return a, nil
}

// NewStdio builds an App for `caddy resource mcp` over stdio: nothing is
// served over HTTP and the directory is re-read on every call.
func NewStdio(ctx context.Context, dir, dsn string) *App {
	a := &App{Dir: dir, Engine: NewEngine()}
	if dsn != "" {
		if pool, err := OpenPool(ctx, dsn); err == nil {
			a.Pool = pool
		}
	}
	a.MCP = &mcp.Server{Backend: a}
	return a
}

func (a *App) Close() {
	if a.Pool != nil {
		a.Pool.Close()
	}
}

// fingerprint summarizes the directory by file names, sizes and modification
// times; it changes whenever an editor writes or renames a file.
func fingerprint(dir string) string {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return "error:" + err.Error()
	}
	h := sha256.New()
	names := make([]string, 0, len(entries))
	for _, e := range entries {
		names = append(names, e.Name())
	}
	sort.Strings(names)
	for _, n := range names {
		info, err := os.Stat(filepath.Join(dir, n))
		if err != nil {
			continue
		}
		fmt.Fprintf(h, "%s\x00%d\x00%d\x00", n, info.Size(), info.ModTime().UnixNano())
	}
	return fmt.Sprintf("%x", h.Sum(nil))
}

// Reload loads and checks the definitions and swaps the served state.
func (a *App) Reload(ctx context.Context) apierr.Report {
	a.reloadMu.Lock()
	defer a.reloadMu.Unlock()
	dir := a.dir()
	fp := fingerprint(dir)
	set, report := Validate(ctx, dir, a.Pool, a.Engine)
	srv := &httpapi.Server{Set: set, Runtime: &resource.Runtime{Pool: a.Pool, Set: set}, OnError: a.OnError, Global: globalErrors(set, report), Output: a.Output}
	if a.MCP != nil {
		srv.MCP = a.MCP
	}
	a.current.Store(&state{set: set, server: srv, report: report, fingerprint: fp})
	if a.OnReload != nil {
		a.OnReload(report)
	}
	return report
}

// globalErrors are the errors attached to no served or broken definition,
// such as a missing directory.
func globalErrors(set *resource.Set, report apierr.Report) apierr.List {
	known := map[string]bool{}
	for _, b := range set.Broken {
		known[b.Name] = true
		known[b.File] = true
	}
	var out apierr.List
	for _, e := range report.Errors {
		if e.Resource == "" || !known[e.Resource] {
			out = append(out, e)
		}
	}
	return out
}

// Watch polls the directory every interval and reloads on change until ctx
// is done. Polling rather than inotify: it sees atomic renames done by
// editors, works on bind mounts and network file systems where inotify
// events are unreliable, and a directory of a few files costs nothing to
// stat twice a second.
func (a *App) Watch(ctx context.Context, interval time.Duration) {
	t := time.NewTicker(interval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			if cur := a.current.Load(); cur == nil || fingerprint(a.dir()) != cur.fingerprint {
				a.Reload(ctx)
			}
		}
	}
}

func (a *App) dir() string {
	if a.DirFunc != nil {
		return a.DirFunc()
	}
	return a.Dir
}

func (a *App) Report() apierr.Report {
	return a.current.Load().report
}

func (a *App) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.current.Load().server.ServeHTTP(w, r)
}

func (a *App) server() *httpapi.Server {
	if !a.serving {
		return nil
	}
	return a.current.Load().server
}

func (a *App) Guide(topic string) (string, error) {
	return guide.Render(topic, guide.Options{MCP: true})
}

func (a *App) Definitions() []*resource.Definition {
	if a.serving {
		return a.current.Load().set.Defs
	}
	set, _, _ := resource.Load(a.dir(), a.Engine)
	return set.Defs
}

// Validate without a definition re-reads the directory; when serving, the
// result is also applied, so the report always matches what is served.
func (a *App) Validate(ctx context.Context, args mcp.ValidateArgs) apierr.Report {
	if args.Definition == "" {
		if a.serving {
			return a.Reload(ctx)
		}
		_, report := Validate(ctx, a.dir(), a.Pool, a.Engine)
		return report
	}
	filename := args.Filename
	if filename == "" {
		filename = "definition.ts"
		if strings.HasPrefix(strings.TrimSpace(args.Definition), "{") {
			filename = "definition.json"
		}
	}
	resolve := func(name string) ([]byte, bool) {
		if args.Query != "" {
			return []byte(args.Query), true
		}
		b, err := os.ReadFile(filepath.Join(a.dir(), name))
		return b, err == nil
	}
	set, errs, _ := resource.LoadInline(filename, []byte(args.Definition), resolve, a.Engine)
	return finish(ctx, set, errs, nil, a.Pool)
}

var errNotServing = &apierr.Error{Code: apierr.NotFound, Message: "resources are only reachable while the server runs", Hint: "Use the /mcp endpoint of the running server."}

func (a *App) List(ctx context.Context, d *resource.Definition, req script.Request, params url.Values) (any, error) {
	srv := a.server()
	if srv == nil {
		return nil, errNotServing
	}
	return srv.ListDocument(ctx, d, req, &url.URL{Path: d.Path, RawQuery: params.Encode()})
}

func (a *App) Get(ctx context.Context, d *resource.Definition, req script.Request, id string) (any, error) {
	srv := a.server()
	if srv == nil {
		return nil, errNotServing
	}
	return srv.ItemDocument(ctx, d, req, id)
}

func (a *App) Create(ctx context.Context, d *resource.Definition, req script.Request, body map[string]any) (any, error) {
	srv := a.server()
	if srv == nil {
		return nil, errNotServing
	}
	id, item, err := srv.Create(ctx, d, req, body)
	if err != nil {
		return nil, err
	}
	if item == nil {
		return fmt.Sprintf("%s %v created; the query does not return it", d.Name, id), nil
	}
	return httpapi.ItemObj(d, item), nil
}

func (a *App) Update(ctx context.Context, d *resource.Definition, op string, req script.Request, id string, body map[string]any) (any, error) {
	srv := a.server()
	if srv == nil {
		return nil, errNotServing
	}
	item, _, err := srv.Update(ctx, d, op, resource.ModePatch, req, id, body)
	if err != nil || item == nil {
		return nil, err
	}
	return httpapi.ItemObj(d, item), nil
}

func (a *App) Operation(ctx context.Context, d *resource.Definition, i int, req script.Request, id string, body map[string]any) (string, error) {
	srv := a.server()
	if srv == nil {
		return "", errNotServing
	}
	return srv.OperationOutput(ctx, d, i, req, id, body)
}
