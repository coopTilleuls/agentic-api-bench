// Package llmapi registers the `resource` Caddy HTTP handler and the
// `caddy resource` commands.
package llmapi

import (
	"net/http"
	"slices"
	"strconv"
	"strings"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig/caddyfile"
	"github.com/caddyserver/caddy/v2/caddyconfig/httpcaddyfile"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"go.uber.org/zap"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/app"
	"github.com/coopTilleuls/agentic-api-bench/internal/httpapi"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

func init() {
	caddy.RegisterModule(Handler{})
	httpcaddyfile.RegisterHandlerDirective("resource", parseCaddyfile)
	httpcaddyfile.RegisterDirectiveOrder("resource", httpcaddyfile.Before, "respond")
}

type Handler struct {
	Dir string `json:"dir,omitempty"`
	DSN string `json:"dsn,omitempty"`
	MCP *bool  `json:"mcp,omitempty"`
	// Site-level output, overridden by the same keys of a definition.
	Formats      []string            `json:"formats,omitempty"`
	Envelope     map[string]string   `json:"envelope,omitempty"`
	Errors       resource.ErrorShape `json:"errors,omitempty"`
	ErrorDetails *bool               `json:"error_details,omitempty"`

	app    *app.App
	logger *zap.Logger
}

func (Handler) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.handlers.resource",
		New: func() caddy.Module { return new(Handler) },
	}
}

func (h *Handler) Provision(ctx caddy.Context) error {
	h.logger = ctx.Logger()
	repl := caddy.NewReplacer()
	dir := repl.ReplaceAll(h.Dir, "")
	if dir == "" {
		dir = app.DefaultDir
	}
	dsn := repl.ReplaceAll(h.DSN, "")
	if dsn == "" {
		dsn = app.DefaultDSN()
	}
	enableMCP := app.MCPDefault()
	if h.MCP != nil {
		enableMCP = *h.MCP
	}
	a, err := app.New(ctx, dir, dsn, enableMCP)
	if err != nil {
		return err
	}
	a.Output = httpapi.Output{Formats: h.Formats, Envelope: h.Envelope, Errors: h.Errors, ErrorDetails: h.ErrorDetails}
	logger := h.logger
	a.OnError = func(r *http.Request, e *apierr.Error) {
		logger.Error("request failed", zap.String("method", r.Method), zap.String("uri", r.RequestURI), zap.String("code", string(e.Code)), zap.String("message", e.Message))
	}
	a.OnReload = func(report apierr.Report) { logReport(logger, dir, report) }
	a.Reload(ctx)
	go a.Watch(ctx, app.PollInterval)
	h.app = a
	return nil
}

func (h *Handler) Cleanup() error {
	if h.app != nil {
		h.app.Close()
	}
	return nil
}

func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request, _ caddyhttp.Handler) error {
	h.app.ServeHTTP(w, r)
	return nil
}

// UnmarshalCaddyfile parses:
//
//	resource [<dir>] {
//		dir <dir>
//		dsn <dsn>
//		mcp on|off
//		formats <format>...
//		envelope {
//			<items|total|page|itemsPerPage|pages> <output key>
//		}
//		error_details on|off
//		errors problem | {
//			<output key> <message|code|status|pointer|hint|resource>
//		}
//	}
func (h *Handler) UnmarshalCaddyfile(d *caddyfile.Dispenser) error {
	d.Next()
	if d.NextArg() {
		h.Dir = d.Val()
		if d.NextArg() {
			return d.ArgErr()
		}
	}
	for d.NextBlock(0) {
		switch d.Val() {
		case "dir":
			if !d.NextArg() {
				return d.ArgErr()
			}
			h.Dir = d.Val()
		case "dsn":
			if !d.NextArg() {
				return d.ArgErr()
			}
			h.DSN = d.Val()
		case "mcp":
			if !d.NextArg() {
				return d.ArgErr()
			}
			v, err := parseOnOff(d.Val())
			if err != nil {
				return d.Errf("mcp: %v", err)
			}
			h.MCP = &v
		case "formats":
			h.Formats = d.RemainingArgs()
			if len(h.Formats) == 0 {
				return d.ArgErr()
			}
			for _, f := range h.Formats {
				if !slices.Contains(resource.AllFormats, f) {
					return d.Errf("formats: unknown format %q; valid: %s", f, strings.Join(resource.AllFormats, ", "))
				}
			}
			continue
		case "error_details":
			if !d.NextArg() {
				return d.ArgErr()
			}
			v, err := parseOnOff(d.Val())
			if err != nil {
				return d.Errf("error_details: %v", err)
			}
			h.ErrorDetails = &v
		case "envelope":
			h.Envelope = map[string]string{}
			for d.NextBlock(1) {
				key := d.Val()
				if !slices.Contains(resource.EnvelopeKeys, key) {
					return d.Errf("envelope: unknown value %q; valid: %s, each followed by its output key", key, strings.Join(resource.EnvelopeKeys, ", "))
				}
				if !d.NextArg() {
					return d.ArgErr()
				}
				h.Envelope[key] = d.Val()
			}
			continue
		case "errors":
			h.Errors = resource.ErrorShape{}
			if d.NextArg() {
				if d.Val() != "problem" {
					return d.Errf("errors: %q is not a shape; write `errors problem` or an `errors { <key> <field> }` block", d.Val())
				}
				break
			}
			for d.NextBlock(1) {
				key := d.Val()
				if !d.NextArg() {
					return d.ArgErr()
				}
				if !slices.Contains(resource.ErrorFields, d.Val()) {
					return d.Errf("errors: unknown field %q; valid: %s", d.Val(), strings.Join(resource.ErrorFields, ", "))
				}
				h.Errors[key] = d.Val()
			}
			continue
		default:
			return d.Errf("unknown option %q in resource block; valid options: dir, dsn%s, formats, envelope, errors, error_details (run `caddy resource guide`)", d.Val(), mcpOption())
		}
		if d.NextArg() {
			return d.ArgErr()
		}
	}
	return nil
}

func mcpOption() string {
	if app.MCPDefault() {
		return ", mcp"
	}
	return ""
}

func parseOnOff(s string) (bool, error) {
	switch s {
	case "on":
		return true, nil
	case "off":
		return false, nil
	}
	if b, err := strconv.ParseBool(s); err == nil {
		return b, nil
	}
	return false, &strconv.NumError{Func: "on|off", Num: s, Err: strconv.ErrSyntax}
}

func parseCaddyfile(h httpcaddyfile.Helper) (caddyhttp.MiddlewareHandler, error) {
	var m Handler
	err := m.UnmarshalCaddyfile(h.Dispenser)
	return &m, err
}

var (
	_ caddy.Provisioner           = (*Handler)(nil)
	_ caddy.CleanerUpper          = (*Handler)(nil)
	_ caddyhttp.MiddlewareHandler = (*Handler)(nil)
	_ caddyfile.Unmarshaler       = (*Handler)(nil)
)

func logReport(logger *zap.Logger, dir string, report apierr.Report) {
	switch {
	case !report.Valid:
		logger.Warn("resource definitions have errors; the affected paths answer them", zap.String("dir", dir), zap.String("report", report.Error()))
	case len(report.Warnings) > 0:
		logger.Warn("resource definitions loaded with warnings", zap.String("dir", dir), zap.String("report", report.Error()))
	default:
		logger.Info("resource definitions loaded", zap.String("dir", dir))
	}
}
