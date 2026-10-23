# Harness

How the runtime and the eval harness work. [`README.md`](../README.md) has the one-page overview and the commands to rerun a cell, [`EXPERIMENT.md`](../EXPERIMENT.md) the protocol and results. A private product contract was also used in the study and is not published; nothing here depends on it.

## The runtime

A Caddy v2 module (`http.handlers.resource`, Go module `github.com/coopTilleuls/agentic-api-bench`) serves a REST API over PostgreSQL from declarative resource definitions. Design notes: [`DESIGN.md`](../DESIGN.md).

### Resources

One definition file per resource, `.json` or `.ts`, in a directory (`resources/` by default). A definition names the resource, its path, its table or a query file, its properties (exactly one `identifier`), filters, sortable properties, relations, helpers and dedicated operations. The JSON Schema in `internal/resource/schema.json` validates both forms and is closed: an unknown key is an error.

Logic that does not fit a definition goes in scripts, picked by extension:

- `.ts`: transpiled by esbuild and run in goja, both inside the binary;
- `.star`: Starlark.

Both get the same `ctx` host API (database, outbound HTTP, JSON, crypto, time, env, `ctx.reject` for business errors). The agent image has no Node, Python, PHP, Ruby or Go, so an agent on this stack cannot write its own server.

The Caddyfile holds a `resource` block:

```
:8089 {
	resource {
		dir resources
		dsn {$DATABASE_URL}
		formats json csv
		envelope { items items  total total  page page  itemsPerPage itemsPerPage }
		errors { error message }
	}
}
```

Options: `dir`, `dsn`, `formats`, `envelope`, `errors`, `error_details`, `mcp on|off`. The handler also serves `GET /` (entrypoint), `GET /openapi.json`, `GET /contexts/<Name>`, and, when MCP is on, `/mcp`. Examples are in `example-v2/Caddyfile` and `example-v2.1/Caddyfile`.

### Guide

The runtime documents itself. `caddy resource guide` prints the core guide (workflow, a worked example, the topic index) and `caddy resource guide <topic>` one topic. Topics (`internal/guide/topics/`): `definitions`, `queries`, `parameters`, `commands`, `operations`, `formats`, `scripts`, `server`, `errors`, `mcp`, `schema`. The `mcp` topic and every MCP mention disappear when MCP is off.

### Commands

`caddy resource <command>` (`commands.go`, `commands_tools.go`):

| Command | What |
| --- | --- |
| `guide [topic]` | Core guide or one topic. |
| `init [--dir .] [--dsn DSN] [--listen :8080]` | Scaffold a Caddyfile and a `resources/` directory with a working example. |
| `validate [--dir resources] [--dsn DSN]` | Static and database checks, JSON report, exit 1 on errors. |
| `introspect [--rows N] [--table NAME] [--json]` | Tables, columns, keys and first rows of the database. |
| `sql "<query>" [--arg v]... [--commit] [--json]` | One statement in a transaction rolled back unless `--commit`. |
| `request METHOD PATH [-H 'Name: value']... [-d BODY\|@file] [--commit]` | A request through the real handler, in-process, no server. Rolled back unless `--commit`. |
| `eval [--lang ts\|starlark] [--env NAME=value]... 'EXPR'` | Evaluate an expression with the script stdlib, offline, printed as JSON. |
| `mcp [--dir resources \| --config Caddyfile] [--dsn DSN] [--http addr]` | Serve the MCP tools over stdio, or over HTTP at `<addr>/mcp`. |

`introspect`, `sql`, `request` and `validate` resolve `dir` and `dsn` from the flags, then from the `resource` block of `./Caddyfile` (or `--config`), then from `$DATABASE_URL`.

The MCP endpoint exposes `framework_guide`, `validate`, `list_resources`, and per resource `list_`, `get_`, `create_`, `update_`, `delete_` tools plus one tool per dedicated operation.

### Errors

Every error is `code`, `message` and a `hint` naming the fix and ending with `see: caddy resource guide <topic>`. `validate` reports `{"valid", "errors": [...], "warnings": [...]}`; each error adds `resource` and a JSON `pointer` into the definition. Checks run in order: JSON syntax or TypeScript compilation, schema, identifier, `table`/`query`, script files and exports, parameters, relations, operations; then, with a DSN, that the table exists, the query runs, every property is a returned column and the types match. Codes are registered in `internal/apierr/codes.go` (`unknown_column`, `unknown_table`, `invalid_parameter`, `filter_not_enabled`, `script_error`, ...), grouped by HTTP status and by topic in the `errors` guide topic. `ctx.reject({code})` codes belong to the script author.

## Build and test

```sh
CGO_ENABLED=0 XCADDY_GO_BUILD_FLAGS="-trimpath -buildvcs=false -ldflags='-s -w'" \
  xcaddy build v2.11.7 --with github.com/coopTilleuls/agentic-api-bench=. --output bin/caddy
bin/caddy resource guide
```

This build embeds the local repository path (xcaddy records the `replace` directory). `eval/run.sh` never uses `bin/caddy`: for the runtime stack it builds `bin/caddy-eval` with `build_binary` (`eval/lib.sh`) from a copy of the sources in a temporary directory, then `check_binary_clean` refuses a binary where `strings` finds the repository path or `$HOME`.

The runtime's tests need the compose Postgres (`postgres:17` on `127.0.0.1:54329`); integration tests are skipped when `LLMAPI_TEST_DSN` is unset:

```sh
docker compose up -d --wait
LLMAPI_TEST_DSN=postgres://llmapi:llmapi@127.0.0.1:54329/llmapi go test ./...
```

## Reference solutions

- `example/`: the v1 contract (`eval/spec.md`): a table resource, a TypeScript definition with a query and a command carrying a business rule, a Starlark query calling an external HTTP API, a CSV operation.
- `example-v2/`: the v2 contract (`eval/v2/spec.md`).
- `example-v2.1/`: v2 after the change request of `eval/v2.1/change-request.md`.

To run one outside Docker: start Postgres (`docker compose up -d --wait`), load the seed into the database named in the Caddyfile, start the stub (`cd eval/docker/stub && STUB_QUOTES=../../stub/quotes.json go run . &`), then `cd example-v2 && ../bin/caddy run`. `STUB_BIN=<built eval/docker/stub> eval/v2/assert.sh http://localhost:8089 example-v2` checks it. In Docker, `eval/score.sh --docker --stack runtime --spec v2 <workdir>` scores a copy whose Caddyfile points at `postgres://eval_rw:eval_rw@postgres:5432/llmapi_eval`.

## The eval

### Contracts

| `--spec` | Contract | Assertions |
| --- | --- | --- |
| `v1` | `eval/spec.md` (runtime only) | `eval/assert.sh` |
| `v2` | `eval/v2/spec.md`, plain JSON | `eval/v2/assert.sh` |
| `v2.1` | `--task evolve`: a change request on a v2 solution | `eval/v2.1/assert.sh` |
| `v3` | `eval/v3/spec.md`, v2 behaviour behind JSON:API 1.1 and an OpenAPI document | `eval/v3/assert.sh`, `eval/v3/validate.py` |

The runtime stack runs v1, v2, v2.1 and v3. The baselines (`nextjs`, `nestjs`, `apiplatform`, `laravel`, `django`, `go`, `rust`) run v2 and v3; `nestjs`, `laravel` and `django` only v3. Baselines never get an MCP server.

### Running

```sh
eval/run.sh --no-mcp --smoke                                   # isolation check only
eval/run.sh --stack runtime --no-mcp --spec v2 --model claude-haiku-5-5
eval/run.sh --stack apiplatform --spec v3 --model claude-haiku-5-5 --max-turns 120
eval/run.sh --task evolve --from eval/results/<v2 run>        # v2.1
```

The header of `eval/run.sh` documents every flag. In short:

| Flag | Meaning |
| --- | --- |
| `--stack` | `runtime` (default), `nextjs`, `nestjs`, `apiplatform`, `laravel`, `django`, `go`, `rust`. |
| `--spec` | `v1` (default), `v2`, `v3`. |
| `--mcp` / `--no-mcp` | Required for `runtime`, refused for baselines. `--no-mcp` sets `LLMAPI_MCP=off`: `/mcp` is disabled and the guide drops MCP. `--mcp` starts the MCP server in a sidecar container before claude and connects it through `--mcp-config`. |
| `--model` | Default `claude-sonnet-5-5`. |
| `--max-turns` | Default 80. |
| `--provider` | `anthropic` (default) or `alibaba`. |
| `--api-key` | Bill the API with `ANTHROPIC_API_KEY` instead of the subscription token. |
| `--scaffolded` / `--no-scaffolded` | Pre-create the project (default for `--spec v3` on `apiplatform`, `nestjs`, `laravel`, `django`; refused elsewhere). |
| `--context`, `--context-dir`, `--apip-ref` | Contexts, see below. |
| `--task evolve --from <run>` | Evolution task, see below. |
| `--smoke` | Trivial prompt, only the init event is checked. |

`EVAL_TIMEOUT` (seconds, default 2700) stops a run still going; it is scored as it is and `timed_out` is written to the run directory. Each run directory records `stack`, `spec`, `model`, `provider`, `max_turns` and `task`, and for scaffoldable stacks `context`, `scaffolded` and `apip_ref`.

The prompt is the contract, a `---` line, then a stack footer (stack, tools available, DSN, how the API will be started: `./caddy run`, or `./start.sh` for every v2 and v3 solution). Results land in `eval/results/<timestamp>-<variant>/`: `prompt.txt`, `init.json`, `verdict.txt`, `transcript.jsonl`, `code/`, `score.txt`, `metrics.json`, `complexity.json`.

### Evolution task

`--task evolve --from <run dir>` takes a v2 run, copies its `code/` into a fresh workdir (the source is never modified), installs the dependencies in the stack image through the proxy (`install.log`; for the runtime the current clean eval binary replaces `./caddy`) and gives a fresh agent the change request, then the v2 spec exactly as the source agent saw it, then the stack footer. The database is the v2 seed plus `eval/v2.1/migration.sql`. The stack and, for the runtime, the MCP variant come from the source run. `metrics.sh` then reports the lines changed against the source code.

### Authentication

The subscription OAuth token comes from `CLAUDE_CODE_OAUTH_TOKEN`, else `~/.config/agentic-api-bench/eval-token` (mode 600, one line, from `claude setup-token`). If `ANTHROPIC_API_KEY` is set the run is refused unless `--api-key` is given. Tokens are passed to the container by variable name (`-e CLAUDE_CODE_OAUTH_TOKEN`), never on a command line or in a log.

### Providers

`--provider alibaba` runs the same CLI against Alibaba Cloud Model Studio's Anthropic-compatible endpoint (Singapore, `https://dashscope-intl.aliyuncs.com/apps/anthropic`) with a non-Claude `--model` (`deepseek-*`, `kimi-*`, `qwen*`). The key is read from `~/.config/agentic-api-bench/alibaba-key` (mode 600) and passed as `ANTHROPIC_AUTH_TOKEN`; `ANTHROPIC_API_KEY` and the OAuth token are unset. The Alibaba host is added to the proxy allowlist, and the Haiku, Sonnet, Opus and subagent model aliases of the CLI all point at `--model`. `--api-key` and `--provider alibaba` are exclusive. The CLI's cost figure prices those tokens as Claude's and is meaningless there: use the token counts (`dataset.sh` leaves `cost_usd` null for non-Anthropic providers).

### Isolation

- **Images.** One `eval/docker/Dockerfile`, a shared `base` stage: the claude CLI as the native standalone binary (version pinned, checksum verified from the release manifest), bash, curl, jq and the external-services stub (a static Go binary from `eval/docker/stub/`). Targets: `runtime` (`llmapi-eval-agent`), `nextjs` (+ Node.js 22, npm; also used for `nestjs`), `apiplatform` (PHP 8.4, Composer, Symfony CLI), `laravel`, `django` (Python 3, pip, venv), `go` (Go 1.25.14 from the official tarball, sha256-checked, `GOTOOLCHAIN=local`, `CGO_ENABLED=0`), `rust` (rustc/cargo 1.90.0, minimal rustup profile, a C linker). The runtime image has no general-purpose interpreter.
- **Filesystem.** The agent runs as the host uid, `HOME` on an empty tmpfs, the workdir (a fresh temporary directory) mounted at `/work`. Go and Rust keep module and crate caches in `/work` (`.gopath`, `.cache`, `.cargo-home`, `target/`) because the agent, install and scoring containers share nothing else and scoring has no network.
- **Network.** `llmapi-eval` is a Docker `--internal` network. It reaches Postgres (alias `postgres`) and a tinyproxy (`llmapi-eval-proxy`, alias `proxy`, `eval/docker/proxy`) with a per-stack allowlist `filter-<stack>`: always `api.anthropic.com`, `claude.ai`, `platform.claude.com`, plus the stack's registry (npm; Packagist, GitHub and Symfony hosts; PyPI; the Go module proxy and checksum database; crates.io). The `apiplatform` proxy of spec v3 also allows `api-platform.com`, whatever the context, so only the files differ between conditions.
- **Database.** `llmapi_eval` is dropped and recreated from the seed of the spec (`eval/seed.sql`, `eval/v2/seed.sql`; v2.1 adds its `migration.sql`, v3 shares the v2 seed) before every run and every scoring. The agent connects as `eval_rw`, which can read and write rows but cannot create objects (`REVOKE CREATE ON SCHEMA public`), so a solution cannot bypass the query layer with views.
- **Skills, plugins, memory.** A user `settings.json` (`eval/docker/claude-settings.json`) set up by `eval/docker/entrypoint.sh`, plus `CLAUDE_CODE_DISABLE_BUNDLED_SKILLS=1` and `CLAUDE_CODE_DISABLE_CLAUDE_MDS=1`, switch off bundled skills, the builtin plugins that can be disabled, and `CLAUDE.md` loading. `cc-plugin-sec-default@builtin` cannot be switched off.
- **Init-event checks.** `run.sh` reads the stream-json init event (saved as `init.json`) as soon as it is written and rejects the run (`verdict.txt`, container removed) unless it shows: no skill; no plugin but `cc-plugin-sec-default@builtin`; memory paths only inside the empty HOME; `apiKeySource` `none` (OAuth) or `ANTHROPIC_API_KEY` with `--api-key`; the requested model; `cwd` `/work`; exactly the expected MCP servers, all connected. With a context, its files are also accepted as memory paths and exactly its plugin and skills are required (none when it has no plugin).
- **MCP.** Claude Code connects MCP servers at startup and retries a failed HTTP server only a few times, so the agent's own server cannot be the one in `--mcp-config`. The framework MCP server therefore runs in a sidecar container over the same `./resources` directory (following the `dir` of `/work/Caddyfile`, read-only), and its command line is not visible to the agent.
- **Archive.** When the agent stops, its workdir is archived into `<run dir>/code/` (without `caddy`, `node_modules`, `vendor`, `var`, `.next`, `public/bundles`, `.git`, `target`, `.gopath`, `.cache`, `.cargo-home`, and per stack `dist`, `storage`, `.venv`; lock files kept), then the workdir is deleted. `code/` is the evidence.

### Contexts

A context is a directory added to the agent's workdir; the condition is the only difference between runs. Spec v3, `--scaffolded` only.

- `--context none` (default): nothing added.
- `--context-dir <dir>`: any stack that can be scaffolded. Files in `<dir>` (`AGENTS.md`, `CLAUDE.md`, ...) are copied to the workdir root after the scaffold and project `CLAUDE.md` loading is switched on (`CLAUDE_CODE_DISABLE_CLAUDE_MDS=0`). An optional `<dir>/plugin/` (`.claude-plugin/plugin.json`, `skills/`) is mounted read-only at `/opt/context-plugin` and loaded with `--plugin-dir`. The run-name suffix and the `context` file are the directory name (`none`, `agents-md`, `skillset`, `shipped` are reserved). The commit (if any) and a tree hash go to `context_rev`, the copied files to `context_files`. Example: `eval/contexts/apiplatform-targeted/`.
- `--context agents-md|skillset` (`apiplatform` only): built from a clone of `https://github.com/api-platform/skillset` (`$SKILLSET_DIR`, default `~/projects/api-platform-skillset`) at its HEAD, recorded in `context_rev`. `agents-md` is its `AGENTS.md` and `CLAUDE.md` (`@AGENTS.md`); `skillset` adds its plugin.
- `--context shipped` (`laravel` only): nothing is added, Claude Code loads the `CLAUDE.md` the Laravel skeleton ships; its sha256 and that of `AGENTS.md` go to `context_rev`.
- `--scaffolded`: the workdir already holds the stack's project with its dependencies installed, created before the agent starts in the stack image through the proxy (`scaffold_cmd` in `eval/lib.sh`): `symfony/skeleton:7.4.*` + `composer require api`, `laravel/laravel` + `laravel-json-api/laravel`, a Python venv with Django, DRF and `djangorestframework-jsonapi`, `@nestjs/cli new`. Logged in `install.log`; resolved top-level versions in `versions.txt`. The prompt footer says the project exists.
- `--apip-ref <sha>` (`apiplatform`, v3, scaffolded): after the scaffold, switch API Platform to the `api-platform/core` monorepo at that commit of `main` (7 to 40 hex digits; `scaffold_apip_ref_cmd` in `eval/lib.sh`; downloaded as a dist from `api.github.com`, already allowed) and set `api_platform.jsonapi.strict_member_names: true`, since the fix is opt-in. Recorded in `apip_ref` and, as `api-platform/core@<ref>`, in `versions.txt`; the run name gets `-patched`.

## Scoring

`eval/run.sh` scores a run when the agent stops; to score a workdir again:

```sh
eval/score.sh --docker --stack apiplatform --spec v3 <workdir>
```

`score.sh` resets the database, then (docker mode, v2 and later) starts `./start.sh` in `<workdir>` inside the stack image on the eval network and waits for `:8089` (30 s, 180 s for `go` and `rust` whose `start.sh` may compile; `--start-wait` or `EVAL_START_WAIT`), printing `start_wait` and `start_ready`. It then runs the assertions of the spec. The scoring container has no network, so `<workdir>` must already have its dependencies. Host mode (v1 only) runs the server and stub on the host.

- **`assert.sh`** (per contract: `eval/assert.sh`, `eval/v2/assert.sh`, `eval/v2.1/assert.sh`, `eval/v3/assert.sh`): fixed curl and jq assertions against the running server, printing `PASS`/`FAIL` lines and a `score: N passed, M failed` line. They run on a freshly seeded database, reads first, then writes. For the runtime stack they also report which script languages the solution used. The agent never sees them.
- **`validate.py`** (v3): the v3 assertions save one document of each kind (collection, item, compound document, error document, the document returned by a create, the OpenAPI document) into a host temporary directory mounted at `/score-out`. `validate.py` then checks them in the network-less `llmapi-eval-validator` image (`eval/docker/validator`, built once): the OpenAPI document is valid (3.0 or 3.1), each JSON:API document validates against the vendored `eval/v3/schemas/jsonapi.json`. Its PASS/FAIL lines are appended and the `score:` line is rewritten to count both. Details: `eval/v3/README.md`.
- **`framework_guard.sh`** (runtime only): after the start, checks that the process listening on `:8089` is the workdir `caddy` binary, compared by content. Otherwise the assertions still run, the output carries a `FRAMEWORK BYPASSED` line and `framework_bypassed: true` goes to `metrics.json`.

## Metrics

`eval/metrics.sh eval/results/<run>` writes `metrics.json`: turns, `total_cost_usd` (notional: the CLI's figure), token usage, `wall_seconds`, authored and repo lines, `framework_bypassed`, and for evolve runs `changed_lines_added`, `changed_lines_removed`, `files_touched`.

**Authored lines** count what the agent wrote, not what a scaffold or a package manager generated:

- A text file is authored when its path is absent from the pristine manifest `eval/scaffolds/<stack>/manifest.tsv`, or when its sha256 differs. New files count all their lines; modified scaffold files only their added lines, from `git diff --no-index --numstat` against `eval/scaffolds/<stack>/files/`. `authored_files` and `scaffold_files_modified` count each kind. The runtime manifest is empty.
- Paths are matched relative to the project root (`project_root` in `eval/lib.sh`: the shallowest directory with `composer.json`, `package.json`, `go.mod` or `Cargo.toml`).
- Never authored, anywhere: `node_modules`, `vendor`, `var`, `.next`, `.git`, `target`, `.gopath`, `.cache`, `.cargo-home`, lock files (`*.lock`, `package-lock.json`, `go.sum`), the runtime `caddy` binary, the context files copied by `--context`. Relative to the project root: `public/bundles/`, `config/reference.php`, `config/bundles.php`, `.env.dev`. Per stack: `dist` (nestjs), `storage/`, `bootstrap/cache/`, `database/database.sqlite` (laravel), `.venv`, `venv`, `__pycache__`, `*.pyc`, `staticfiles` (django). The exact patterns are `AUTH_EXCL_RE`, `PROJ_EXCL_RE` and the per-stack variants in `eval/lib.sh`.
- `repo_loc` is the line count of the workdir under the same dependency exclusions, with `scc` when installed, else `find | wc -l`. `tool_written_loc` counts only files created through Write/Edit/MultiEdit calls (it misses Bash heredocs; kept for reference). `scaffold_command` is the scaffolding command the agent ran, if any.
- Limitation: an agent scaffolding with other versions or flags than the pinned ones gets the files that differ only because of that counted as authored. Rebuild a manifest with `eval/scaffold.sh <stack>` (pinned versions, generated in the stack image through its proxy).

`eval/complexity.sh <run dir>` (called by `metrics.sh`; writes `complexity.json` and `complexity` in `metrics.json`) measures the authored content only, same set as above:

- imperative code (`.ts .tsx .js .mjs .cjs .php .go .rs`, `.star` staged as `.py`) with `ast-metrics` v0.43.1 (downloaded on first use into `eval/tools/ast-metrics`, checked against a pinned sha256, run without network when `unshare` allows it): `cc_total`, `cc_max_file`, `mi_avg`, `lloc`, `functions`, `parse_failures`. Modified scaffold files are measured whole (`modified_whole`); generated `*.d.ts` are skipped;
- declarative config (`.json`, `.yaml`): `config_keys`, `config_leaves`, counting only what a modified scaffold file adds;
- SQL found in string, template and heredoc literals and `.sql` files by a lexer heuristic (`eval/tools/src`, a small Go program built on first use into the ignored `eval/tools/bin/`): `sql_statements`, `sql_joins`, `sql_subqueries`, `sql_where_predicates`, `sql_tokens`, `qb_calls`. The limits of the heuristic are in the header of `eval/tools/src/sql.go`; SQL assembled from fragments across variables is missed or split.

## Dataset and summary

```sh
eval/dataset.sh      # eval/results/dataset.json
eval/summarize.sh    # eval/results/summary.md
```

Both read every run directory of `eval/results/` whose name starts with a timestamp, skipping smoke runs (`summarize.sh` also skips `*harness-failed*`; `dataset.sh` skips runs without `metrics.json`). `summarize.sh` accepts run directories as arguments and computes missing metrics and complexity on the way.

- `dataset.json`: one object per run, sorted by run name: `run`, `date`, `task`, `spec`, `stack`, `variant`, `model`, `provider`, `context`, `scaffolded`, `apip_ref`, `score_passed`, `score_failed`, `score_total`, `turns`, `cost_usd`, `wall_seconds`, `authored_loc`, `authored_files`, evolve counts, `tokens` (input, cache read, cache creation, output), `complexity` (`cc_total`, `lloc`, `config_keys`, `sql_statements`) and `flags` (`aborted`, `timed_out`, `framework_bypassed`).
- `summary.md`: a table with one row per run (model, stack, spec, task, variant, score, failed assertions, script languages, turns, wall time, cost, tokens, authored and repo LOC, CC, MI, config keys, SQL statements and joins, evolve diff, result, whether the agent called `guide` and `validate` and how, Bypassed), followed by an appendix per run with its first three actions and the error codes it received.
