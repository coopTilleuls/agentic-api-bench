package llmapi

import (
	"fmt"
	"os"
	"strings"

	"github.com/caddyserver/caddy/v2"
	"github.com/spf13/cobra"
)

const (
	frameworkTagline = "declarative REST API framework for PostgreSQL (start here: caddy resource guide)"
	resourceShort    = "Declarative REST API framework over PostgreSQL: run `caddy resource guide` first"
	noConfigHint     = "hint: to build a REST API over PostgreSQL, run `caddy resource init` to scaffold a Caddyfile and resources/, and `caddy resource guide` for the workflow."
	decoratedKey     = "llmapi.decorated"
)

const rootLong = `This Caddy build includes "resource": a declarative REST API framework
(JSON-LD/Hydra, JSON, CSV, OpenAPI) over PostgreSQL. Building an API on a
database? Start with: caddy resource guide

Caddy is an extensible server platform; modules are plugged in at compile
time. To run it:

	- 'caddy run' runs Caddy in the foreground (recommended).
	- 'caddy start' starts it in the background; stop it with 'caddy stop'.

If a file named Caddyfile is in the current directory it is used, otherwise
pass --config. Use 'caddy adapt' to see how a config translates to JSON, and
'caddy list-modules' to see the installed modules.

Full documentation: https://caddyserver.com/docs/
`

var resourceCmd *cobra.Command

func init() {
	if caddy.CustomLongDescription == "" {
		caddy.CustomLongDescription = rootLong
	}
	// Caddy registers commands but exposes neither the root command nor a hook
	// to extend core commands; the first command executed reaches the root
	// through the attached "resource" command.
	cobra.OnInitialize(func() {
		if resourceCmd != nil {
			decorateRoot(resourceCmd.Root())
		}
	})
}

func decorateRoot(root *cobra.Command) {
	if root.Annotations[decoratedKey] != "" {
		return
	}
	if root.Annotations == nil {
		root.Annotations = map[string]string{}
	}
	root.Annotations[decoratedKey] = "1"

	for _, c := range root.Commands() {
		switch c.Name() {
		case "version":
			appendOutput(c, func(cmd *cobra.Command) {
				fmt.Fprintf(cmd.OutOrStdout(), "with resource: %s\n", frameworkTagline)
			})
		case "list-modules":
			appendOutput(c, func(cmd *cobra.Command) {
				if asJSON, _ := cmd.Flags().GetBool("json"); asJSON {
					return
				}
				fmt.Fprintf(cmd.OutOrStdout(), "\n  Framework: http.handlers.resource  %s\n", frameworkTagline)
			})
		case "run":
			hintOnMissingCaddyfile(c)
		case "validate", "adapt", "reload":
			hintOnNoConfigError(c)
		}
	}
}

func appendOutput(c *cobra.Command, after func(*cobra.Command)) {
	orig := c.RunE
	if orig == nil {
		return
	}
	c.RunE = func(cmd *cobra.Command, args []string) error {
		if err := orig(cmd, args); err != nil {
			return err
		}
		after(cmd)
		return nil
	}
}

func hintOnMissingCaddyfile(c *cobra.Command) {
	orig := c.RunE
	if orig == nil {
		return
	}
	c.RunE = func(cmd *cobra.Command, args []string) error {
		config, _ := cmd.Flags().GetString("config")
		resume, _ := cmd.Flags().GetBool("resume")
		if config == "" && !resume {
			if _, err := os.Stat("Caddyfile"); err != nil {
				fmt.Fprintln(cmd.ErrOrStderr(), "no Caddyfile in the current directory; Caddy will start with an empty configuration.")
				fmt.Fprintln(cmd.ErrOrStderr(), noConfigHint)
			}
		}
		return orig(cmd, args)
	}
}

func hintOnNoConfigError(c *cobra.Command) {
	orig := c.RunE
	if orig == nil {
		return
	}
	c.RunE = func(cmd *cobra.Command, args []string) error {
		err := orig(cmd, args)
		if err != nil && isNoConfigError(err) {
			fmt.Fprintln(cmd.ErrOrStderr(), noConfigHint)
		}
		return err
	}
}

func isNoConfigError(err error) bool {
	msg := err.Error()
	for _, s := range []string{"no config file to load", "no Caddyfile in current directory", "open Caddyfile: no such file"} {
		if strings.Contains(msg, s) {
			return true
		}
	}
	return false
}
