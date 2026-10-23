package llmapi

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"

	"github.com/caddyserver/caddy/v2/caddyconfig"
	caddycmd "github.com/caddyserver/caddy/v2/cmd"
	"github.com/spf13/cobra"

	"github.com/coopTilleuls/agentic-api-bench/internal/app"
	"github.com/coopTilleuls/agentic-api-bench/internal/guide"
)

const resourceLong = `Builds a JSON-LD (Hydra), JSON or CSV + OpenAPI REST API over PostgreSQL
from resource definition files.

Start with the core guide (workflow, a complete example, the topic index):

	caddy resource guide

then read one topic when you need it:

	caddy resource guide <topic>

Inspect and try things without a server or throwaway files:

	caddy resource introspect                 tables, columns, keys, first rows
	caddy resource sql "SELECT ..."           one statement, rolled back by default
	caddy resource request GET /books         a request through the real handler
	caddy resource eval 'ctx.crypto.sha256("x")'   the script stdlib, offline
`

func init() {
	caddycmd.RegisterCommand(caddycmd.Command{
		Name:      "resource",
		Usage:     "<guide [topic]|init|validate|introspect|sql|request|eval>",
		Short:     resourceShort,
		Long:      resourceLong,
		CobraFunc: configureResource,
	})
}

func configureResource(cmd *cobra.Command) {
	resourceCmd = cmd
	cmd.AddCommand(guideCommand(), initCommand(), validateCommand(), mcpCommand(), introspectCommand(), sqlCommand(), requestCommand(), evalCommand())
	cmd.Args = cobra.ArbitraryArgs
	cmd.RunE = func(cmd *cobra.Command, args []string) error {
		if len(args) == 0 {
			return cmd.Help()
		}
		cmd.SilenceUsage = true
		return fmt.Errorf("unknown command %q for \"caddy resource\"; commands: guide, init, validate, introspect, sql, request, eval. Start with: caddy resource guide", args[0])
	}
}

func guideCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "guide [topic]",
		Short: "Print the core guide (read this first) or one topic",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			topic := ""
			if len(args) == 1 {
				topic = args[0]
			}
			text, err := guide.Render(topic, guide.Options{MCP: app.MCPDefault()})
			if err != nil {
				cmd.SilenceUsage = true
				return err
			}
			fmt.Fprint(cmd.OutOrStdout(), text)
			return nil
		},
	}
}

func validateCommand() *cobra.Command {
	var dir, dsn string
	c := &cobra.Command{
		Use:   "validate [--dir resources] [--dsn DSN]",
		Short: "Validate resource definitions, print a JSON report, exit 1 on errors",
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := context.Background()
			if dsn == "" {
				dsn = app.DefaultDSN()
			}
			engine := app.NewEngine()
			var report any
			valid := true
			if dsn != "" {
				pool, perr := app.OpenPool(ctx, dsn)
				if perr != nil {
					return printReport(cmd, map[string]any{"valid": false, "errors": []any{perr}, "warnings": []any{}})
				}
				defer pool.Close()
				_, r := app.Validate(ctx, dir, pool, engine)
				report, valid = r, r.Valid
			} else {
				_, r := app.Validate(ctx, dir, nil, engine)
				report, valid = r, r.Valid
			}
			if err := printReport(cmd, report); err != nil {
				return err
			}
			if !valid {
				os.Exit(1)
			}
			return nil
		},
	}
	c.Flags().StringVar(&dir, "dir", app.DefaultDir, "definitions directory")
	c.Flags().StringVar(&dsn, "dsn", "", "PostgreSQL DSN (default $DATABASE_URL); without it only static checks run")
	return c
}

func printReport(cmd *cobra.Command, report any) error {
	b, err := json.MarshalIndent(report, "", "  ")
	if err != nil {
		return err
	}
	fmt.Fprintln(cmd.OutOrStdout(), string(b))
	if m, ok := report.(map[string]any); ok && m["valid"] == false {
		os.Exit(1)
	}
	return nil
}

func mcpCommand() *cobra.Command {
	var dir, dsn, listen, config string
	c := &cobra.Command{
		Use:    "mcp [--dir resources | --config Caddyfile] [--dsn DSN] [--http 127.0.0.1:8090]",
		Short:  "Serve the MCP tools over stdio, or over HTTP at <addr>/mcp with --http",
		Hidden: !app.MCPDefault(),
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := context.Background()
			if dsn == "" {
				dsn = app.DefaultDSN()
			}
			if listen == "" {
				a := app.NewStdio(ctx, dir, dsn)
				defer a.Close()
				return a.MCP.ServeStdio(ctx, cmd.InOrStdin(), cmd.OutOrStdout())
			}
			a, err := app.New(ctx, dir, dsn, true)
			if err != nil {
				return err
			}
			if config != "" {
				a.DirFunc = func() string { return dirFromCaddyfile(config) }
			}
			a.Reload(ctx)
			defer a.Close()
			go a.Watch(ctx, app.PollInterval)
			mux := http.NewServeMux()
			mux.Handle("/mcp", a.MCP)
			fmt.Fprintf(cmd.ErrOrStderr(), "MCP endpoint: http://%s/mcp (definitions: %s)\n", listen, dir)
			return http.ListenAndServe(listen, mux)
		},
	}
	c.Flags().StringVar(&dir, "dir", app.DefaultDir, "definitions directory")
	c.Flags().StringVar(&dsn, "dsn", "", "PostgreSQL DSN (default $DATABASE_URL)")
	c.Flags().StringVar(&listen, "http", "", "serve MCP over HTTP on this address instead of stdio")
	c.Flags().StringVar(&config, "config", "", "with --http: follow the dir option of the resource block in this Caddyfile, re-read on every reload")
	return c
}

func initCommand() *cobra.Command {
	var dir, dsn, listen string
	c := &cobra.Command{
		Use:   "init [--dir .] [--dsn DSN] [--listen :8080]",
		Short: "Scaffold a Caddyfile and a resources/ directory with a working example",
		RunE: func(cmd *cobra.Command, _ []string) error {
			if dsn == "" {
				dsn = "{$DATABASE_URL}"
			}
			files := map[string]string{
				"Caddyfile":                   fmt.Sprintf(initCaddyfile, listen, dsn),
				"resources/Greeting.ts":       initDefinition,
				"resources/greeting.query.ts": initQuery,
			}
			for _, name := range []string{"Caddyfile", "resources/Greeting.ts", "resources/greeting.query.ts"} {
				path := filepath.Join(dir, name)
				if _, err := os.Stat(path); err == nil {
					fmt.Fprintf(cmd.OutOrStdout(), "skipped %s (already exists)\n", path)
					continue
				}
				if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
					return err
				}
				if err := os.WriteFile(path, []byte(files[name]), 0o644); err != nil {
					return err
				}
				fmt.Fprintf(cmd.OutOrStdout(), "created %s\n", path)
			}
			fmt.Fprintf(cmd.OutOrStdout(), "\nNext: ./caddy resource validate --dsn '<dsn>' then ./caddy run, then curl localhost%s/greetings\nRead `caddy resource guide` for the workflow and the topic index.\n", listen)
			return nil
		},
	}
	c.Flags().StringVar(&dir, "dir", ".", "where to create the Caddyfile and resources/")
	c.Flags().StringVar(&dsn, "dsn", "", "PostgreSQL DSN written into the Caddyfile (default {$DATABASE_URL})")
	c.Flags().StringVar(&listen, "listen", ":8080", "listen address of the site")
	return c
}

const initCaddyfile = `{
	admin off
}

%s {
	resource {
		dir resources
		dsn %s
	}
}
`

const initDefinition = `// Read-only resource backed by a custom query. GET /greetings, GET /greetings/1.
export default defineResource({
  name: "Greeting",
  path: "/greetings",
  query: "greeting.query.ts",
  properties: {
    id: { type: "integer", identifier: true },
    message: { type: "string", filterable: true },
  },
});
`

const initQuery = `// Works on any database: replace the VALUES list with a SELECT on your tables.
export default function query(ctx: QueryContext): Sql {
  return ctx.sql` + "`" + `SELECT * FROM (VALUES (1, 'hello'), (2, 'world')) AS t(id, message)` + "`" + `;
}
`

// dirFromCaddyfile returns the definitions directory configured in the first
// resource handler of a Caddyfile, or the default when there is none (yet).
func dirFromCaddyfile(path string) string {
	h, err := handlerFromCaddyfile(path)
	if err != nil || h == nil || h.Dir == "" {
		return app.DefaultDir
	}
	return h.Dir
}

// handlerFromCaddyfile returns the first resource handler of a Caddyfile as
// the module reads it, or nil when the file has none.
func handlerFromCaddyfile(path string) (*Handler, error) {
	body, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	adapter := caddyconfig.GetAdapter("caddyfile")
	if adapter == nil {
		return nil, fmt.Errorf("no caddyfile adapter")
	}
	cfg, _, err := adapter.Adapt(body, map[string]any{"filename": path})
	if err != nil {
		return nil, err
	}
	var doc any
	if err := json.Unmarshal(cfg, &doc); err != nil {
		return nil, err
	}
	raw := findResourceHandler(doc)
	if raw == nil {
		return nil, nil
	}
	b, err := json.Marshal(raw)
	if err != nil {
		return nil, err
	}
	h := &Handler{}
	if err := json.Unmarshal(b, h); err != nil {
		return nil, err
	}
	return h, nil
}

func findResourceHandler(v any) map[string]any {
	switch x := v.(type) {
	case map[string]any:
		if x["handler"] == "resource" {
			return x
		}
		for _, e := range x {
			if h := findResourceHandler(e); h != nil {
				return h
			}
		}
	case []any:
		for _, e := range x {
			if h := findResourceHandler(e); h != nil {
				return h
			}
		}
	}
	return nil
}
