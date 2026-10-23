package llmapi

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"sort"
	"strings"

	"github.com/caddyserver/caddy/v2"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/spf13/cobra"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/app"
	"github.com/coopTilleuls/agentic-api-bench/internal/dbtool"
	"github.com/coopTilleuls/agentic-api-bench/internal/httpapi"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
)

const caddyfileName = "Caddyfile"

// target is where the tool commands find the definitions and the database,
// resolved like the module does: flag, then the resource block of the
// Caddyfile, then DATABASE_URL and the default directory.
type target struct {
	dir, dsn, config string
}

func (t *target) flags(c *cobra.Command) {
	c.Flags().StringVar(&t.dir, "dir", app.DefaultDir, "definitions directory (default: the dir of the Caddyfile, else resources)")
	c.Flags().StringVar(&t.dsn, "dsn", "", "PostgreSQL DSN (default: the dsn of the Caddyfile, else $DATABASE_URL)")
	c.Flags().StringVar(&t.config, "config", "", "Caddyfile holding the resource block (default: ./Caddyfile when it exists)")
}

type settings struct {
	dir, dsn string
	output   httpapi.Output
}

func (t *target) resolve(cmd *cobra.Command) (settings, error) {
	s := settings{dir: t.dir, dsn: t.dsn}
	config, explicit := t.config, t.config != ""
	if !explicit {
		if _, err := os.Stat(caddyfileName); err == nil {
			config = caddyfileName
		}
	}
	if config != "" {
		h, err := handlerFromCaddyfile(config)
		switch {
		case err != nil && explicit:
			return s, &apierr.Error{Code: apierr.UsageError, Message: fmt.Sprintf("cannot read the resource block of %s: %v", config, err), Hint: "Pass --config with a Caddyfile holding a `resource` block, or --dir and --dsn."}
		case err == nil && h != nil:
			repl := caddy.NewReplacer()
			if !cmd.Flags().Changed("dir") && h.Dir != "" {
				s.dir = repl.ReplaceAll(h.Dir, "")
			}
			if s.dsn == "" {
				s.dsn = repl.ReplaceAll(h.DSN, "")
			}
			s.output = httpapi.Output{Formats: h.Formats, Envelope: h.Envelope, Errors: h.Errors, ErrorDetails: h.ErrorDetails}
		case err == nil && explicit:
			return s, &apierr.Error{Code: apierr.UsageError, Message: fmt.Sprintf("%s has no resource block", config), Hint: "Add `resource { dir resources  dsn ... }` to a site of the Caddyfile."}
		}
	}
	if s.dsn == "" {
		s.dsn = app.DefaultDSN()
	}
	if s.dsn == "" {
		return s, &apierr.Error{Code: apierr.NoDSN, Message: "no database configured", Hint: "Pass --dsn, or --config Caddyfile (./Caddyfile is read when present), or set DATABASE_URL."}
	}
	return s, nil
}

func (t *target) open(cmd *cobra.Command) (settings, *pgxpool.Pool, error) {
	s, err := t.resolve(cmd)
	if err != nil {
		return s, nil, err
	}
	pool, perr := app.OpenPool(context.Background(), s.dsn)
	if perr != nil {
		return s, nil, perr
	}
	return s, pool, nil
}

func usageArgs(n int, usage string) cobra.PositionalArgs {
	return func(cmd *cobra.Command, args []string) error {
		if len(args) == n {
			return nil
		}
		cmd.SilenceUsage = true
		return &apierr.Error{Code: apierr.UsageError, Message: fmt.Sprintf("%s takes %d argument(s), got %d", cmd.Name(), n, len(args)), Hint: "Usage: caddy resource " + usage}
	}
}

func usageError(message, hint string) error {
	return &apierr.Error{Code: apierr.UsageError, Message: message, Hint: hint}
}

func introspectCommand() *cobra.Command {
	var t target
	var rows int
	var table string
	var asJSON bool
	c := &cobra.Command{
		Use:   "introspect [--rows N] [--table NAME] [--json]",
		Short: "Print the tables of the database: columns, keys and the first rows",
		Long: `Reads the schema of the database the resource module uses and prints, for every
table (or only --table NAME): one line per column with its type, "not null",
"pk", default and foreign key (-> table.column), then the first --rows rows
(default 3, 0 disables) ordered by primary key. Values are cut at 60 characters.

The database is resolved like the module does: --dsn, else the dsn of the
Caddyfile (--config, or ./Caddyfile), else $DATABASE_URL.`,
		Args: usageArgs(0, "introspect [--rows N] [--table NAME] [--json]"),
		RunE: func(cmd *cobra.Command, _ []string) error {
			cmd.SilenceUsage = true
			if rows < 0 {
				return usageError("--rows must be 0 or more", "Use --rows 0 to skip the rows, --rows 3 for three per table.")
			}
			_, db, err := t.open(cmd)
			if err != nil {
				return err
			}
			defer db.Close()
			schema, err := dbtool.Introspect(context.Background(), db, table, rows)
			if err != nil {
				return err
			}
			if asJSON {
				return schema.WriteJSON(cmd.OutOrStdout())
			}
			schema.WriteText(cmd.OutOrStdout())
			return nil
		},
	}
	t.flags(c)
	c.Flags().IntVar(&rows, "rows", 3, "rows to print per table, ordered by primary key (0: none)")
	c.Flags().StringVar(&table, "table", "", "describe only this table")
	c.Flags().BoolVar(&asJSON, "json", false, "print structured JSON instead of the compact text")
	return c
}

func sqlCommand() *cobra.Command {
	var t target
	var args []string
	var commit, asJSON bool
	c := &cobra.Command{
		Use:   `sql "<query>" [--arg value]... [--commit] [--json]`,
		Short: "Run one SQL statement in a transaction rolled back unless --commit",
		Long: `Runs one statement against the database of the resource module and prints the
rows as an aligned table, or "N rows affected". The transaction is rolled back
unless --commit is given; stderr says which happened. $1..$n are bound to the
repeated --arg values, in order.

The database is resolved like the module does: --dsn, else the dsn of the
Caddyfile (--config, or ./Caddyfile), else $DATABASE_URL.`,
		Args: usageArgs(1, `sql "<query>" [--arg value]... [--commit] [--json]`),
		RunE: func(cmd *cobra.Command, query []string) error {
			cmd.SilenceUsage = true
			_, db, err := t.open(cmd)
			if err != nil {
				return err
			}
			defer db.Close()
			res, err := dbtool.RunSQL(context.Background(), db, query[0], args, commit)
			if err != nil {
				return err
			}
			if res.Committed {
				fmt.Fprintln(cmd.ErrOrStderr(), "committed")
			} else {
				fmt.Fprintln(cmd.ErrOrStderr(), "rolled back (use --commit to keep changes)")
			}
			if asJSON {
				return res.WriteJSON(cmd.OutOrStdout())
			}
			res.WriteText(cmd.OutOrStdout())
			return nil
		},
	}
	t.flags(c)
	c.Flags().StringArrayVar(&args, "arg", nil, "value of the next $n parameter (repeat for $1, $2, ...)")
	c.Flags().BoolVar(&commit, "commit", false, "keep the changes instead of rolling back")
	c.Flags().BoolVar(&asJSON, "json", false, "print rows as JSON")
	return c
}

func requestCommand() *cobra.Command {
	var t target
	var headers []string
	var data string
	var commit bool
	c := &cobra.Command{
		Use:   "request METHOD PATH [-H 'Name: value']... [-d BODY|@file] [--commit]",
		Short: "Send a request through the real handler in-process, no server",
		Long: `Loads the definitions of --dir exactly as the server does and answers the
request through the same HTTP handler, without a listener. The whole request
runs in one database transaction, rolled back unless --commit is given
(ctx.fetch calls still go out). Prints "HTTP/1.1 <status>", the response headers
sorted, a blank line and the body (JSON pretty-printed). The exit code is 0
whatever the status; it is 1 only when the definitions have errors, which are
printed as "caddy resource validate" prints them.

-d takes the body, or @file to read it from a file (@- for stdin); a body
without Content-Type is sent as application/json.

The database and directory are resolved like the module does: --dsn and --dir,
else the Caddyfile (--config, or ./Caddyfile), else $DATABASE_URL.`,
		Args: usageArgs(2, "request METHOD PATH [-H 'Name: value']... [-d BODY|@file] [--commit]"),
		RunE: func(cmd *cobra.Command, args []string) error {
			cmd.SilenceUsage = true
			method, path := strings.ToUpper(args[0]), args[1]
			if !strings.HasPrefix(path, "/") {
				return usageError(fmt.Sprintf("PATH %q must start with /", path), "Pass the path and query string as the server would receive them: /books?page=2.")
			}
			body, err := requestBody(cmd, data)
			if err != nil {
				return err
			}
			req, err := buildRequest(method, path, headers, body)
			if err != nil {
				return err
			}
			s, db, err := t.open(cmd)
			if err != nil {
				return err
			}
			defer db.Close()
			res, report, err := app.Request(context.Background(), s.dir, db, s.output, req, commit)
			if err != nil {
				return err
			}
			if res == nil {
				cmd.SilenceErrors = true
				if err := printJSON(cmd, report); err != nil {
					return err
				}
				return fmt.Errorf("the definitions in %s have errors", s.dir)
			}
			if commit {
				fmt.Fprintln(cmd.ErrOrStderr(), "committed")
			} else {
				fmt.Fprintln(cmd.ErrOrStderr(), "rolled back (use --commit to keep changes)")
			}
			writeResponse(cmd.OutOrStdout(), res)
			return nil
		},
	}
	t.flags(c)
	c.Flags().StringArrayVarP(&headers, "header", "H", nil, "request header, 'Name: value' (repeatable)")
	c.Flags().StringVarP(&data, "data", "d", "", "request body, or @file to read it from a file (@- for stdin)")
	c.Flags().BoolVar(&commit, "commit", false, "keep the changes instead of rolling back")
	return c
}

func requestBody(cmd *cobra.Command, data string) ([]byte, error) {
	name, ok := strings.CutPrefix(data, "@")
	if !ok {
		return []byte(data), nil
	}
	if name == "-" {
		return io.ReadAll(cmd.InOrStdin())
	}
	b, err := os.ReadFile(name)
	if err != nil {
		return nil, usageError(fmt.Sprintf("cannot read the body file: %v", err), "-d @path reads the body from a file; -d '{...}' passes it inline.")
	}
	return b, nil
}

func buildRequest(method, path string, headers []string, body []byte) (*http.Request, error) {
	req, err := http.NewRequest(method, "http://localhost"+path, bytes.NewReader(body))
	if err != nil {
		return nil, usageError(fmt.Sprintf("invalid request: %v", err), "Pass METHOD (GET, POST, ...) and PATH (/books?page=2).")
	}
	for _, h := range headers {
		name, value, ok := strings.Cut(h, ":")
		if name = strings.TrimSpace(name); !ok || name == "" {
			return nil, usageError(fmt.Sprintf("header %q is not 'Name: value'", h), "Write -H 'Accept: application/json'.")
		}
		if strings.EqualFold(name, "Host") {
			req.Host = strings.TrimSpace(value)
			continue
		}
		req.Header.Add(name, strings.TrimSpace(value))
	}
	if len(body) > 0 && req.Header.Get("Content-Type") == "" {
		req.Header.Set("Content-Type", "application/json")
	}
	return req, nil
}

func writeResponse(w io.Writer, res *app.Response) {
	fmt.Fprintf(w, "HTTP/1.1 %d\n", res.Status)
	names := make([]string, 0, len(res.Header))
	for name := range res.Header {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		for _, v := range res.Header[name] {
			fmt.Fprintf(w, "%s: %s\n", name, v)
		}
	}
	fmt.Fprintln(w)
	body := res.Body
	var pretty bytes.Buffer
	if len(body) > 0 && json.Valid(body) && json.Indent(&pretty, bytes.TrimSpace(body), "", "  ") == nil {
		body = pretty.Bytes()
	}
	_, _ = w.Write(body)
	if len(body) > 0 && body[len(body)-1] != '\n' {
		fmt.Fprintln(w)
	}
}

func evalCommand() *cobra.Command {
	var lang string
	var env []string
	c := &cobra.Command{
		Use:   "eval [--lang ts|starlark] [--env NAME=value]... 'EXPR'",
		Short: "Evaluate an expression with the script stdlib, offline, and print it as JSON",
		Long: `Evaluates one expression with the script stdlib available and prints its value
as JSON: ctx.crypto, ctx.time, ctx.env and ctx.json. There is no database, request
or network. ctx.env(NAME) reads the --env values first, then the process
environment. The language is TypeScript (default) or Starlark.

	caddy resource eval 'ctx.crypto.hmac("sha256", "key", "1700000000" + "tok")'
	caddy resource eval --lang starlark 'ctx.time.format(1700000000)'`,
		Args: usageArgs(1, "eval [--lang ts|starlark] [--env NAME=value]... 'EXPR'"),
		RunE: func(cmd *cobra.Command, args []string) error {
			cmd.SilenceUsage = true
			values := map[string]string{}
			for _, e := range env {
				name, value, ok := strings.Cut(e, "=")
				if !ok || name == "" {
					return usageError(fmt.Sprintf("--env %q is not NAME=value", e), "Write --env SECRET=s3cr3t.")
				}
				values[name] = value
			}
			out, err := script.Eval(app.NewEngine(), lang, args[0], values)
			if err != nil {
				return err
			}
			fmt.Fprintln(cmd.OutOrStdout(), out)
			return nil
		},
	}
	c.Flags().StringVar(&lang, "lang", "ts", "script language: ts or starlark")
	c.Flags().StringArrayVar(&env, "env", nil, "NAME=value for ctx.env (repeatable)")
	return c
}

func printJSON(cmd *cobra.Command, v any) error {
	b, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	fmt.Fprintln(cmd.OutOrStdout(), string(b))
	return nil
}
