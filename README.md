# agentic-api-bench

Do coding agents use an API framework the way it was designed, and what context changes that?

This repository holds the harness, the tasks, the reference solutions and every published run behind two articles:

<!-- TODO: replace with the final URLs once the articles are published -->
- [Coding agents and API Platform: what they read decides](https://les-tilleuls.coop/blog/api-platform-agents)
- [Agentic context matters in API design](https://till.ia/blog/agentic-context)

A blank Claude Code agent (no skills, no plugins, no project instructions unless the run adds them) gets a written HTTP contract and a stack, builds the API in an isolated container, and is scored over HTTP by a fixed set of assertions it never sees. The runs vary the stack (a self-describing runtime, API Platform, Next.js, NestJS, Go, Rust) and the context given to the agent (none, the official API Platform `AGENTS.md`, the official skillset, a targeted `AGENTS.md`).

[`EXPERIMENT.md`](EXPERIMENT.md) is the full lab notebook: hypotheses, protocol, every batch, results and threats to validity.

[`docs/harness.md`](docs/harness.md) explains how the runtime and the eval harness work: isolation, contexts, scoring, metrics.

## Layout

| Path | What |
| --- | --- |
| `*.go`, `internal/` | The self-describing runtime: a Caddy v2 module (`http.handlers.resource`) serving a REST API over PostgreSQL from declarative definitions. Design notes: [`DESIGN.md`](DESIGN.md). |
| `example/`, `example-v2/`, `example-v2.1/` | Reference solutions of the bookshop contracts on the runtime. |
| `eval/run.sh` | Runs one agent in its container, then scores and measures the result. |
| `eval/score.sh`, `eval/assert.sh`, `eval/*/assert.sh` | Scoring: HTTP assertions per contract. |
| `eval/metrics.sh`, `eval/complexity.sh` | Authored lines, tokens, turns, cost, complexity of a run. |
| `eval/dataset.sh`, `eval/summarize.sh` | Build `eval/results/dataset.json` and `eval/results/summary.md` from the run directories. |
| `eval/spec.md`, `eval/v2/`, `eval/v2.1/`, `eval/v3/` | The bookshop contracts: v1, v2 (plain JSON), v2.1 (a change request on v2), v3 (JSON:API 1.1 and OpenAPI). |
| `eval/v3/reference/` | v3 reference solutions with API Platform, Laravel and Django, each used as its documentation intends. |
| `eval/v3/validate.py`, `eval/v3/schemas/` | OpenAPI and JSON:API document validation for v3. |
| `eval/contexts/apiplatform-targeted/` | The targeted `AGENTS.md` context. |
| `eval/docker/` | Agent, stack, proxy, stub and validator images. |
| `eval/scaffolds/` | Pinned scaffold manifests per stack, used to count only the lines an agent wrote. |
| `eval/tools/` | Small Go tool counting JSON/YAML keys and SQL statements. |
| `eval/results/` | One directory per run: prompt, `init.json`, full transcript, archived code, score, metrics. |
| `research/` | Framework findings while writing the v3 references, and prior art. |

## Rerun one cell

Prerequisites:

- Docker with the compose plugin (Postgres, the agent and stack images, the proxy, the validator are all built or started by the scripts);
- `bash`, `jq`, `curl`;
- for the runtime stack only: Go and [`xcaddy`](https://github.com/caddyserver/xcaddy), `eval/run.sh` builds the binary itself;
- a Claude Code OAuth token from `claude setup-token`, passed in the environment as `CLAUDE_CODE_OAUTH_TOKEN` (or stored in `~/.config/agentic-api-bench/eval-token`, mode 600). It is passed to the container by variable name and never written to the logs. `ANTHROPIC_API_KEY` is refused unless `--api-key` is given.

The v3 API Platform cells of the articles ran on Claude Haiku with 120 turns and 60 minutes at most:

```sh
export CLAUDE_CODE_OAUTH_TOKEN=...   # from `claude setup-token`
export EVAL_TIMEOUT=3600

# no context
eval/run.sh --stack apiplatform --spec v3 --model claude-haiku-5-5 --max-turns 120

# official AGENTS.md, or the official skillset (AGENTS.md plus the Claude Code plugin);
# both need a clone of https://github.com/api-platform/skillset in $SKILLSET_DIR
SKILLSET_DIR=~/src/api-platform-skillset eval/run.sh --stack apiplatform --spec v3 --context agents-md --model claude-haiku-5-5 --max-turns 120
SKILLSET_DIR=~/src/api-platform-skillset eval/run.sh --stack apiplatform --spec v3 --context skillset --model claude-haiku-5-5 --max-turns 120

# targeted AGENTS.md
eval/run.sh --stack apiplatform --spec v3 --context-dir eval/contexts/apiplatform-targeted --model claude-haiku-5-5 --max-turns 120

# any of the above on a patched API Platform (batch 7 used api-platform/core 94c70a08a)
eval/run.sh --stack apiplatform --spec v3 --apip-ref 94c70a08a --model claude-haiku-5-5 --max-turns 120

# NestJS, and the runtime on the earlier contracts
eval/run.sh --stack nestjs --spec v3 --model claude-haiku-5-5 --max-turns 120
eval/run.sh --stack runtime --spec v2 --no-mcp --model claude-haiku-5-5
```

`eval/run.sh --no-mcp --smoke` only checks the isolation (trivial prompt, init event verified). The header of [`eval/run.sh`](eval/run.sh) documents every flag. Results land in `eval/results/<timestamp>-<spec>-<stack>[-<context>][-patched]/`.

## Score

`eval/run.sh` scores every run when the agent stops (`score.txt`) and computes its metrics (`metrics.json`). To score a workdir again:

```sh
eval/score.sh --docker --stack apiplatform --spec v3 <workdir>
```

The scoring container has no network, so `<workdir>` needs its dependencies installed. Archived `code/` directories exclude `vendor/` and `node_modules/`: install them in the stack image first. Then rebuild the tables:

```sh
eval/dataset.sh      # eval/results/dataset.json
eval/summarize.sh    # eval/results/summary.md
```

The runtime's own tests need the compose Postgres:

```sh
docker compose up -d --wait
LLMAPI_TEST_DSN=postgres://llmapi:llmapi@127.0.0.1:54329/llmapi go test ./...
```

## Limits

- Three runs per cell, one task per contract. The results are tendencies, not rates.
- Almost every run uses Claude Haiku. A few early runs use Claude Sonnet.
- The targeted `AGENTS.md` was written by the API Platform maintainer, who also wrote the contracts and the scorer.
- The first independent task was derived from the HTTP contract of a private product. Its contract, assertions and runs are not published. `EXPERIMENT.md` keeps its numbers and conclusions, and the run directories it cites for that task are absent.
- The cost figures are the Claude Code CLI's notional `total_cost_usd`. The runs used a subscription, not API billing.

## License

[MIT](LICENSE).
