// Package script defines the host API offered to definition, query and
// command scripts, independent of the language runtime executing them.
package script

import (
	"context"
	"net/http"
	"net/url"

	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

// HostModule is the only specifier a script may import from. Everything it
// exports is also available as a global.
const HostModule = "caddy-resource"

type Request struct {
	Method string
	Path   string
	Query  url.Values
	// RawQuery keeps the order of the query string (order[a]=asc&order[b]=desc).
	RawQuery string
	Header   http.Header
	Body     string
}

type FetchRequest struct {
	URL     string
	Method  string
	Headers map[string]string
	Body    string
}

type FetchResponse struct {
	Status  int
	Headers map[string]string
	Body    []byte
}

// Host is what a script can reach outside its sandbox. Errors returned by a
// Host should be *apierr.Error: they surface unchanged to the caller.
type Host interface {
	Query(ctx context.Context, f sqlf.Fragment) ([]map[string]any, error)
	Exec(ctx context.Context, f sqlf.Fragment) (int64, error)
	Fetch(ctx context.Context, r FetchRequest) (*FetchResponse, error)
	Env(name string) (string, error)
}

// Resources gives scripts ctx.resource(name): SQL fragments generated from
// the definitions' metadata.
type Resources interface {
	Resource(name string) (SQLHelper, error)
}

// SQLHelper generates fragments for one resource (or one relation of it):
// identifiers are quoted and inlined, values are bound.
type SQLHelper interface {
	Table() sqlf.Fragment
	Alias() string
	Column(name string) (sqlf.Fragment, error)
	// Columns selects the given properties (nil: the default list) aliased
	// so the row mapper fills them.
	Columns(names []string) (sqlf.Fragment, error)
	Identifier(v any) sqlf.Fragment
	Join(relation string) (sqlf.Fragment, error)
	Relation(name string) (SQLHelper, error)
	JSON(names []string) (sqlf.Fragment, error)
}

// Call is everything a script function receives besides the host.
// Operation is "collection", "item", "create", "update" or "delete".
// Phase is "read", or "lookup" when a write looks up the item it changes.
type Call struct {
	Request      Request
	Host         Host
	Operation    string
	Phase        string
	Validating   bool
	Page         int
	ItemsPerPage int
	Filters      map[string]any
	ID           any
	Input        map[string]any
	// Values backs ctx.get/ctx.set: parsed parameters and what handlers set.
	Values    map[string]any
	Resources Resources
	// Value is the argument of a parameter handler (fn "handler").
	Value any
	// Property backs ctx.property in handlers: the quoted column of the
	// query result holding a property, for conditions added around it.
	Property func(name string) (sqlf.Fragment, error)
	// RawAllowed lets the script return ctx.response(...): true only for the
	// query or command of a dedicated operation.
	RawAllowed bool
}

// HandlerFunc is the function name passed to Module.Call for a parameter
// handler: the default export (TypeScript) or `def handler` (Starlark),
// called with (value, ctx).
const HandlerFunc = "handler"

// Module is a loaded script. Call invokes an exported function: "default"
// receives (ctx), any other export receives (input, ctx). The result is
// plain Go data: nil, bool, int64, float64, string, []any, map[string]any or
// sqlf.Fragment.
type Module interface {
	Exports() []string
	Call(ctx context.Context, fn string, call Call) (any, error)
}

type Engine interface {
	EvalDefinition(filename string, src []byte) ([]byte, error)
	Load(filename string, src []byte) (Module, error)
}
