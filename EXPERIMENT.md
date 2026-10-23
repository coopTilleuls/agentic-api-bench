# Experiment: can a blank agent build a correct API from a self-describing runtime?

Status: in progress, started 2026-10-19. v1 batch done (12 runs), v2 pilot done (4 runs), batch 2 done (4 builds + 4 evolutions), independent task on a private product's contract done (4 builds). Prototype spec: [`DESIGN.md`](DESIGN.md). The research journal and the original study are not published.

## Why

Ask a coding agent with no instructions to build an application and it answers Next.js, Supabase or Postgres, Prisma, Vercel. Corpus volume explains part of it, but these stacks also maximise the odds that the first iteration runs: a scaffolding command, zero infrastructure configuration, a typed textual feedback loop (`tsc`), deployment included. PHP frameworks, API Platform included, lose on those points rather than on quality, and an agent with no prior knowledge will not pick them.

The thesis of this experiment: the problem is not "the agent does not know my framework" but "the agent needs prior knowledge". A runtime that describes itself makes corpus presence optional: the agent discovers instead of remembering. Three description surfaces, fed by the same resource metadata:

- the data describes itself (JSON-LD/Hydra, generated OpenAPI);
- the framework describes itself to the agent (a generated guide, an MCP endpoint);
- errors teach (structured, actionable errors instead of stack traces, the equivalent of the `tsc` loop).

The success criterion is not "the agent chooses my framework", a distribution problem played over years. It is: **an agent that has never seen a line of this framework produces a correct API on the first try**. That is falsifiable in days.

## Hypotheses

| # | Hypothesis | Falsified by |
| --- | --- | --- |
| H1 | A blank agent builds a correct API on the first try through discovery and the error loop | Score of `eval/score.sh` on blank agents, no-MCP variant |
| H2 | An MCP endpoint improves the outcome beyond what error messages give | Score gap between `--mcp` and `--no-mcp` |
| H3 | A custom query composes with pagination, filters and item lookup, so the agent never hand-writes that SQL | Query-backed resources pass the pagination/filter assertions |
| H4 | An embedded script runtime (single binary) is enough for commands, external API calls and business rules | Reference solution and eval cover commands, `ctx.fetch`, a business rule |
| H5 | The script language matters: agents do better in Python syntax (Starlark) than TypeScript | Language freely chosen by the agent and success rate per language |
| H7 | For the same spec and score, this runtime costs less than stacks agents already know (Next.js, API Platform): tokens, code written, time to deliver | Same spec, container and scorer on Next.js and API Platform runs; tokens from transcript usage, lines written excluding dependencies and generated code, wall time, turns |
| H6 | A blank client agent consumes a Hydra/JSON-LD API as well as an OpenAPI-only one | Deferred, protocol to define |

## The prototype

A Caddy v2 module (`http.handlers.resource`, Go, single static binary built with `xcaddy`) that serves a REST API over PostgreSQL from declarative resource definitions. Embedding in Caddy gives TLS, auth, rate limiting, HTTP cache, compression and Mercure in one binary with no sidecar, which is the point compared to PostgREST, Hasura or Supabase.

What a definition looks like, and what the runtime does with it:

- **Resources** are `.json` or `.ts` files: name, path, `properties` (the output shape, which is the resource), an `identifier`, optional `formats`. Either backed by a `table` or by a `query` script.
- **Query, the escape hatch.** A query script returns a parameterised SQL fragment. The host wraps it (`SELECT * FROM (<query>) r WHERE <filters> ORDER BY <id> LIMIT/OFFSET`, plus count and item lookup), so pagination, filters and lookup apply to custom queries exactly as to tables. `table` is sugar for the same code path. A query may also return rows instead of SQL (external API, computed data); the host then hands pagination and filters to the script.
- **Command.** Writes are validated against an `input` block (an input DTO, as in API Platform; there is no output DTO, the resource is the output). Table resources get generated `INSERT`/`UPDATE`/`DELETE`; a command script runs in a transaction and returns the identifier, and the response is read back through the query (command writes, query reads).
- **Host API for scripts:** `ctx.sql`, `ctx.db.query/exec` (same transaction), `ctx.fetch` (allowed hosts only), `ctx.env` (allowed names only), `ctx.reject`, `ctx.request`.
- **Two script languages**, picked by file extension, same host API: TypeScript run by goja (pure Go JS engine) after in-process transpilation by esbuild, and Starlark (Python syntax, pure Go, hermetic). Python reflexes Starlark rejects (`import`, `class`, f-strings, `try`) each produce a teaching error.
- **Formats:** JSON-LD/Hydra in the API Platform 4 shape (unprefixed `member`, `totalItems`, `view`, `/contexts/*`, problem+json errors), plain JSON, CSV. Dedicated `operations` (method, path, pinned format, own query/command) cover the common report case, e.g. `GET /business/export` returning CSV. Declaring operations replaces the default CRUD, as in API Platform.
- **Errors teach:** every error, at validation and at runtime, is `{code, resource, pointer, message, hint}`. Postgres errors are mapped to codes with hints, never passed raw.
- **Always serve:** the server starts whatever the state of the definitions. An invalid definition is served as its errors on its paths, valid ones keep working, definitions hot-reload from disk (polling, 500 ms). The agent's loop is edit then request, no restart.
- **Self-description:** `caddy resource guide` prints an agent guide generated from the code (definition schema, host API, error code table), `caddy resource init` scaffolds, `caddy resource validate` prints a JSON report, `/openapi.json`, and an MCP server (`/mcp`, or `caddy resource mcp`) exposing `framework_guide`, `validate`, `list_resources` and per-resource tools.

### Decisions that depart from the study

- **goja + esbuild rather than WASM.** The study credited only the WASM path with "single binary and the agent writes TypeScript". goja and esbuild, both pure Go and in-process, hold both promises; WASM needs a TS-to-WASM toolchain outside the binary, a worse loop for the agent. The engine sits behind an interface so wazero can still be measured.
- **TypeScript buys less than it seems.** esbuild strips types without checking them, so the `tsc` feedback loop that makes Next.js win is not inherited. Typed feedback has to come from the framework's own validation.
- **Starlark as a second engine** to test "agents love Python" with data. CPython was ruled out: cgo or a ~25 MB python.wasm breaks the single binary or the startup time.

## The eval harness

**Task.** [`eval/spec.md`](eval/spec.md) describes a bookshop API in plain functional terms, never how to build it: authors (table CRUD, pagination), published books only (forces a custom query with a join and a visibility rule, filter by author name, pagination, 404 on unpublished), a business rule (a book whose author has no name cannot be published: 422, nothing stored), quotes proxied from an external HTTP service (forces `ctx.fetch` and a non-SQL query), and a CSV export endpoint. The prompt is the spec plus the DSN and "the only tool you have is the `./caddy` binary in this directory". No documentation, no mention of formats or languages.

**Reference solution.** [`example/`](example/) solves the spec with every feature (table, TS query + command, Starlark query calling the external service, pinned CSV operation). The scorer must pass on it before any eval run counts.

**Blank agent.** The agent must not know this framework or its authors' conventions, so it runs in a Docker container ([`eval/docker/`](eval/docker/)):

- non-root, `HOME` on an empty tmpfs, the only mount is `/work` holding the binary alone (copied to a fresh temp dir, so no source, design notes or study are reachable);
- internal Docker network reaching only Postgres and a proxy that allows the Anthropic API domains;
- database role with row access only, no DDL, so the agent cannot bypass the query layer with views;
- bundled skills, builtin plugins and CLAUDE.md loading disabled; the one builtin plugin that cannot be disabled (`cc-plugin-sec-default`) is accepted and recorded;
- auth by the subscription OAuth token passed by variable name, never in logs; runs refuse an `ANTHROPIC_API_KEY` unless asked;
- every run is rejected unless its stream-json `init` event shows the expected model, `cwd=/work`, no skills, no plugin beyond the one above, memory paths inside the empty home, `apiKeySource: none`, and exactly the expected MCP servers. The init event is saved with the transcript as proof.

**Variants.** `--no-mcp`: `/mcp` disabled and MCP absent from the guide. `--mcp`: the framework MCP server is started before the agent and connected through `--mcp-config`. The CLI does not reliably connect to an MCP server that appears after startup, so the agent's own server cannot be the target; the pre-started one exposes `framework_guide`, `validate` and `list_resources`, which is precisely the "framework describes itself to the agent" surface H2 is about. Per-resource tools serve API clients, not the builder.

**Scoring.** [`eval/score.sh`](eval/score.sh) resets the database, starts the agent's `./caddy run` in the same image and network, and runs 32 fixed HTTP assertions ([`eval/assert.sh`](eval/assert.sh)) covering every bullet of the spec. The model never grades itself. The scorer also records which script languages the solution used. It was checked against breakage: a copy of the reference solution with the visibility filter removed and a resource deleted fails 10 assertions.

**Protocol.** 3 runs per variant per model (`claude-sonnet-5-5`, `claude-haiku-5-5`), sequential, no intervention, no retry: a failed run is data. A run is only redone when the harness itself broke (init check rejected, container crash), and that is reported.

## Results

First batch, 2026-10-19, 12 runs, claude-code 2.1.296. Full table, first actions and error codes per run: [`eval/results/summary.md`](eval/results/summary.md), generated by `eval/summarize.sh`. Costs are the CLI's notional figure; runs go through a Claude subscription, not API billing.

| Model | Variant | Score | Turns (per run) | Wall time s (per run) | Notional cost (mean) |
| --- | --- | --- | --- | --- | --- |
| claude-sonnet-5-5 | no-mcp | 32/32 ×3 | 14, 16, 13 | 70, 63, 57 | $0.24 |
| claude-sonnet-5-5 | mcp | 32/32 ×3 | 9, 7, 11 | 39, 36, 49 | $0.17 |
| claude-haiku-5-5 | no-mcp | 32/32 ×3 | 13, 17, 21 | 96, 116, 140 | $0.03 |
| claude-haiku-5-5 | mcp | 32/32 ×3 | 26, 26, 13 | 109, 87, 70 | $0.02 |

Every run ended `success`, with no harness failure and no rerun. All 12 solutions used JSON definitions and TypeScript scripts; none used Starlark.

Reading, with the caveats below:

- **H1 holds on this spec**, for both models: 12/12 blank agents delivered a fully correct API on the first try, in one to two and a half minutes. The spec is too easy to say more.
- **H2: no gap in success, a gap in effort for Sonnet.** With MCP, Sonnet needed about half the turns (7–11 vs 13–16) and ~35% less wall time. Haiku shows no consistent difference. Three runs per cell is too few to call it.
- **H3, H4 hold on this spec**: query composition (visibility filter + pagination + author filter), a command with a business rule, `ctx.fetch` to an external service and a CSV operation were all built by every agent without hand-written pagination SQL.
- **H5 is untested**: 12/12 chose TypeScript. The guide shows TypeScript first, which may decide it; the language choice has to be forced or the guide order randomised to measure anything.
- **Errors taught.** Agents hit few errors (0 to 9 per run), mostly `rejected` (their own business rule while testing it), `not_found` and `required_property`; Haiku MCP runs also hit `missing_identifier`. Every run recovered from every error.

### Leaks found in the transcripts

- **The binary is readable.** Several agents ran `strings ./caddy`; one Sonnet run learned the definition format from the guide and schema text embedded in the binary without ever running `caddy resource guide`. That is legitimate self-description through another door, not contamination, but it shows agents probe the artifact itself.
- **Host build paths in the binary.** It was built without `-trimpath`, so `strings` exposed the repository's absolute path on the build host; two agents tried to read it. The container has no such path and they found nothing, but the eval binary must be built with `-trimpath`.
- **MCP variant asymmetry.** `ps` shows the pre-started MCP server's command line (`resource mcp --config /work/Caddyfile`), revealing the file conventions the no-MCP variant cannot see. Part of the Sonnet MCP speed-up may come from that, not from the MCP tools.

## Spec v2 and baselines (pilot)

**Why a v2.** Spec v1 was written alongside the runtime and every run maxed it out. Spec v2 ([`eval/v2/spec.md`](eval/v2/spec.md)) is stack-agnostic, pins a plain JSON contract (`{items, total, page, itemsPerPage}`, `{error}`) and is designed to break things: embedded author, combinable filters (partial, range, date, boolean, multi-key sort), client page size, per-customer access by header (401/404), a purchase touching stock with an external payment (402/502, full rollback), a CSV report with a date range. Scored by 172 HTTP assertions ([`eval/v2/assert.sh`](eval/v2/assert.sh)), validated on the reference solution (172/172) and on a deliberately broken copy (138/172, failing exactly the visibility, payment, rollback and stock assertions).

**Writing the reference first exposed the runtime.** Against v2, the v1 runtime could not meet 9 requirements and met 3 more only through hand-written pagination/filter/sort SQL, the failure mode the original study predicted. That reshaped the runtime (see `DESIGN.md`, "v2 design"):

- **Parameters**, one primitive for every HTTP query or header value that changes the response, after API Platform's `QueryParameter`/`HeaderParameter`: built-in filters (exact, partial/ipartial with LIKE escaping, range, date, boolean, multi-key order with id tiebreak, 400 on malformed input), client page size within bounds, header parameters checked before body validation, script handlers for the rest. Declared, hence documented in OpenAPI, the guide and MCP.
- **SQL first, after [esql](https://github.com/soyuka/esql)**: business SQL stays hand-written, the metadata generates the error-prone parts (`columns()`, `join()`, `identifier()`); aliases map to nested objects. Hard for humans, a good fit for agents.
- Configurable envelope and error shape (the teaching fields `code`/`hint`/`pointer` stay as extra keys), `object`/`array`/`date` types, explicit `output` per operation, separate lookup/read phases for writes.

The rewritten reference has no hand-written plumbing; the agent guide grew from 444 to 663 lines, a cost to measure.

**Baselines (H7).** The same spec built with Next.js and API Platform, same container isolation (stack toolchain, package registry through the proxy), same scorer, `./start.sh` for every stack. Metrics per run: tokens by type, turns, wall time, and `authored_loc`, lines the agent created or added relative to a pinned scaffold of its stack (`eval/scaffolds/`), so generated boilerplate does not count.

**Pilot, 2026-10-19/20, Haiku, one run per cell.**

| Cell | Score | Turns | Wall time | Notional cost | Authored lines (files) |
| --- | --- | --- | --- | --- | --- |
| runtime, no MCP | 172/172 | 24 | 237 s | $0.15 | 243 (15) |
| runtime, MCP | 172/172 | 27 | 213 s | $0.08 | 244 (14) |
| Next.js | 172/172 | 20 | 172 s | $0.03 | 618 (16) |
| API Platform | 167/172, hit the 80-turn cap | 81 | 530 s | $0.56 | 1351 (37) |

- **An agent with no framework at all delivers.** In the first runtime run, the image still contained Node (the CLI was an npm package). Haiku ignored `./caddy`, wrote its own Node HTTP server and a PostgreSQL wire-protocol client with SCRAM authentication from scratch, and passed all 172 assertions in 207 s. The run was discarded and the harness fixed (native CLI, Go stub, no interpreter in the runtime image, scorer check that the process serving :8089 is the `caddy` binary). In the valid rerun the agent again first looked for an interpreter, then used the framework. Agents reach for what they know before what they are given.
- **Finishing does not separate stacks at this size.** Three of four cells score the maximum within four minutes. What separates them is the amount of code left to maintain (243 vs 618 vs 1351 lines) and cost and turns, where Next.js wins on this spec.
- **API Platform was the only partial failure** (CSV report grouping and UTC day bounds), hit the turn cap, and cost about ten times the others.
- n=1 per cell. The Next.js, API Platform and runtime-MCP runs ran on the previous image (npm CLI, Node present); only the runtime no-MCP rerun ran on the fixed one.

## Batch 2: builds and evolution (2026-10-20, Haiku, one run per cell)

Same cells as the pilot, now all on the interpreter-free image, the progressive guide (100-line core, topics on demand) and archived code. Each build is followed by an evolution run: a fresh agent applies [`eval/v2.1/change-request.md`](eval/v2.1/change-request.md) (header rename, `pages` in every list, default page size, `q` search, `hasPublishedBooks`, refunds with a schema migration, report excluding refunds) to the code the build produced. Evolution is scored on 172 regression + 111 change assertions.

| Cell | Build score | Build turns / time / cost | Authored lines | Evolution score | Evolution turns / time / cost | Lines changed (files) |
| --- | --- | --- | --- | --- | --- | --- |
| runtime, no MCP | **2/172**, gave up | 5 / 25 s / $0.01 | 0 | 283/283 (rebuilt from scratch) | 49 / 342 s / $0.39 | +278 (18), not a diff |
| runtime, MCP | 172/172 | 27 / 200 s / $0.05 | 235 | 283/283 | 27 / 84 s / $0.02 | +51 −3 (10) |
| Next.js | 172/172 | 33 / 737 s / $0.05 | 746 | 283/283 | 26 / 88 s / $0.02 | +89 −11 (9) |
| API Platform | 172/172 | 44 / 367 s / $0.19 | 1105 | 283/283 | 35 / 121 s / $0.04 | +124 −14 (8) |

Authored lines for API Platform were first reported higher (1308) because Symfony-generated files (`config/reference.php`, `config/bundles.php`, `.env.dev`) counted as authored; they are now excluded, checked by hand file by file against the transcript. The pilot's API Platform and Next.js figures (1351, 618) used the earlier metric and their code was not archived, so they cannot be recomputed and are not comparable.

Static complexity of the code each agent wrote ([`eval/complexity.sh`](eval/complexity.sh): ast-metrics v0.43.1 for TS/PHP/Starlark, a small tool for JSON/YAML and SQL), builds of batch 2:

| | runtime, MCP | Next.js | API Platform |
| --- | --- | --- | --- |
| Cyclomatic complexity, total | 34 | 155 | 147 |
| Logical lines of code | 48 | 243 | 234 |
| Functions | 10 | 59 | 50 |
| Maintainability index, mean per file | 78 | 60 | 81 |
| JSON/YAML keys written | 189 | 9 | 8 |
| SQL statements / joins | 12 / 4 | 18 / 6 | 15 / 5 (+23 QueryBuilder calls) |

On our runtime the imperative complexity moves into declarative configuration rather than disappearing, which is why both are reported. The maximum is per file, not per function (ast-metrics' per-function maximum is wrong), and SQL assembled from fragments is undercounted.

- **Discovery can fail outright.** The runtime no-MCP build ran `./caddy version` and `./caddy list-modules`, did not recognise `http.handlers.resource` as a database layer, found no interpreter, concluded "Caddy has no SQL module" and gave up in 5 turns, asking for Python or a PostgREST. It never ran `./caddy help` or `./caddy resource`. Earlier runs of the same cell found the framework through `strings` and `caddy help`; the eval binary is now stripped. The evolution agent that inherited the empty directory did find it (`strings`, a `caddy adapt` probe, then the guide) and built everything. H1 as stated ("discovers instead of remembering") holds only if the first commands an agent types lead to the framework. With MCP connected, discovery never failed.
- **Fixing the entry points fixed discovery.** The binary now names the framework and points to `caddy resource guide` from `caddy version`, `caddy` / `caddy help`, `list-modules` and the no-config errors. Three new runtime no-MCP builds (Haiku): 3/3 at 172/172, the guide reached at the 2nd or 3rd tool call (previously the 8th–10th, or never), 20–44 turns, 226–315 s, $0.11–0.27, 224–233 authored lines. Agents still dump `caddy resource guide all` rather than reading topics on demand, so the cost stays above Next.js.
- **Every evolution passed fully**, including regression. Change cost: the runtime diff is the smallest (54 lines vs 100 for Next.js and 138 for API Platform), but time and cost are the same as Next.js. API Platform is a bit slower and dearer.
- **Variance is high.** API Platform went from 167/172 at the turn cap (pilot) to 172/172 in 44 turns; the Next.js build went from 172 s to 737 s. n=1 per cell supports no ranking yet.
- **Agents still probe the sandbox.** They look for interpreters first, and one MCP-variant agent grepped the filesystem for seed data (`grep -rl Earthsea /`). Isolation held: no interpreter, and the test assertions are never inside the agent's container.

## Independent task: a private product's contract (2026-10-20, Haiku, one build per cell)

> **Not published.** This task was derived from a private product. Its contract, its assertions, its scoring support in the harness and its run directories are not part of this repository. Batches 3 to 5 below mostly ran on it; their numbers and conclusions are kept, the run directories they cite are absent.

To break the circularity of a spec, framework, reference and scorer designed together, this task comes from a real product that predates the experiment: only its HTTP contract (5 endpoints) and 109 curl/jq assertions derived from its own tests (78) or code (31). No reference solution; the framework was measured as is. Unlike the bookshop it is little CRUD and much logic: input normalisation, sliding-window rate limits, an HMAC-SHA256 signed callback, a multi-step write with events, a page answered in HTML or JSON.

Cross-stack rule applied: one assertion (an empty JSON object sent to the signed callback must be a 400) failed on all four stacks; the contract says "body empty or not a JSON object: 400" and `{}` is a JSON object, so it is a contract ambiguity and is excluded (scores out of 108).

| Cell | Score | Turns | Time | Notional cost | Authored lines | CC total | Config keys | SQL stmts |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| runtime, no MCP | 102/108, hit the 80-turn cap | 81 | 819 s | $0.90 | 508 | 77 | 83 | 20 |
| runtime, MCP | 88/108, hit the 80-turn cap | 81 | 764 s | $1.02 | 483 | 66 | 108 | 24 |
| Next.js | **108/108** | 13 | 343 s | $0.03 | 550 | 92 | 5 | 19 |
| API Platform | **108/108** | 56 | 368 s | $0.22 | 821 | 88 | 24 | 20 |

- **On a logic-heavy API the runtime loses clearly**: lower score, the turn cap in both cells, about 30× the cost of Next.js, and no reduction in code (508 vs 550 lines, similar complexity). The declarative advantage seen on the bookshop belongs to CRUD; it does not carry over to business logic.
- **Missing standard library, not missing discovery.** The no-MCP agent implemented SHA-256 and HMAC by hand in TypeScript (162 lines) because scripts have no crypto; the MCP agent computed the HMAC in SQL with PostgreSQL's `sha256()` and got a 500 on every callback call. There is no HTML output for the content-negotiated page either. Next.js and API Platform have all of this in their platform (`crypto`, Symfony).
- **The runtime-only `404000` failures were not a framework bug.** curl exited with error 18 (33 bytes missing): the agents' Caddyfile served HTML pages with `rewrite` + `templates` + `file_server` around the API, and when `templates` failed on a 404, Caddy's `handle_errors` answered with the `Content-Length` that `file_server` had already set. Caddy v2.11.7 reproduces it without the resource module. The framework gap was upstream of it: agents had no way to return a custom status or an HTML page, so they built that workaround. Scripts now have `ctx.response` for that.

## Batch 3: script standard library, Go and Rust (2026-10-20, Haiku, one run per cell)

After the private task, scripts got a standard library (`ctx.crypto`: hashes, HMAC checked against RFC 4231, constant-time compare, random, UUID, base64/hex; `ctx.time`; `ctx.response` for raw HTML/redirects with `ctx.request.accepts()`), and two baselines were added: pure Go (1.25, any module through the Go proxy) and Rust (1.90, any crate). Wall time includes compilation.

| Task | Cell | Score | Turns | Wall time | Notional cost | Authored lines | CC total | SQL stmts |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| private | runtime, no MCP, with stdlib | 108/108 | 48 | 472 s | $0.51 | 404 | 97 | 25 |
| private | Go | 108/108 | 12 | 186 s | $0.03 | 794 | 144 | 15 |
| private | Rust | 108/108 | 20 | 410 s | $0.05 | 739 | 137 | 16 |
| v2 bookshop | Go | 172/172 | 18 | 238 s | $0.04 | 950 | 166 | 17 |
| v2 bookshop | Rust | 172/172 | 23 | 580 s | $0.05 | 780 | 162 | 18 |

For comparison on the private task before the standard library: runtime 102/108 at the turn cap, 819 s, $0.90; Next.js 108/108, 343 s, $0.03; API Platform 108/108, 368 s, $0.22.

- **The standard library closed the score gap** (102 → 108, turn cap no longer hit) and halved the cost, but the runtime still costs 10–15× Go or Rust and takes more turns than any other stack. Agents in other stacks write code from memory immediately; on the runtime they read the guide, write definitions and scripts, and iterate on teaching errors.
- **The runtime writes the least code**: 404 lines on the private task (Go 794, Rust 739, Next.js 550) and the lowest imperative complexity among compiled baselines; on the bookshop Go and Rust write 780–950 lines against 235 for the runtime.
- **Go is the cheapest and fastest stack overall**; Rust matches it in tokens, and compilation makes it the slowest in wall time.

## Batch 4: cutting the runtime's token cost (2026-10-20, Haiku, private task)

A transcript analysis of 9 runs showed the runtime was not expensive because of its errors (0.5–2% of cost, the hint was always enough) or of the code it writes, but because its context grew: the guide was 20–29% of the final context and was re-read every turn (one MCP run loaded every topic at once, 64k characters), and a probe phase (throwaway servers, temporary resources just to read the database or compute a test HMAC, workarounds for missing features) took the context from 40k to 112k tokens before the first real file was written. Two changes followed:

- **Tools that remove the probes**: `caddy resource introspect` (tables, columns, keys, sample rows), `caddy resource sql` and `caddy resource request` (a request through the real handler, in-process, no server), both in a transaction rolled back unless `--commit`, with one savepoint per statement so a caught error behaves as on the server; `caddy resource eval` (script stdlib offline). Scripts get the raw body (`ctx.request.body`, `ctx.request.json()`, also with `input: {}`), and property names are free JSON keys (`order-data`). The previous run had copied the body into an `X-Raw-Body` header from the Caddyfile to get around both gaps.
- **A guide on a diet**: 97,748 → 39,579 characters (≈10k tokens). `guide all` is gone; the `schema` topic is a generated outline instead of 24k of raw JSON Schema; the `errors` topic is generated from the error registry. A test enforces the budgets and checks that ~280 identifiers of the old guide are still documented.

The first two runs exposed a framework bug that predated these changes: an item operation (`POST /…/{id}`) whose `output` omits the identifier read the id back from the projected row, got nil and ran the command with the string `"<nil>"`, answering 204 without writing anything. Fixed (the id comes from the path; Hydra `@id` no longer depends on `output`), and `ctx.response` is now allowed in item-operation commands, which an earlier run had worked around by rewriting POST to GET in the Caddyfile. The last two runs are on the fixed framework.

| Run | Framework | Score | Turns | Wall time | Notional cost | Cache read | Authored lines |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 131938 (batch 3) | before | 108/108 | 48 | 472 s | $0.51 | 5.66M | 404 |
| 153345 | tools + guide | 106/108 | 38 | 370 s | $0.31 | 3.48M | 338 |
| 154012 | tools + guide | 97/108 (id bug) | 34 | 350 s | $0.24 | 2.90M | 331 |
| 160206 | + fixes | 106/108 | 39 | 334 s | $0.18 | 3.04M | 417 |
| 160757 | + fixes | 106/108 | 28 | 267 s | $0.10 | 1.91M | 368 |

- **Cost down 65–81%** on the fixed framework. The runtime is now below API Platform ($0.22) but still 3–6× Go ($0.03), Next.js ($0.03) and Rust ($0.05). The agents use `introspect` on their first turns, then `sql` and `request`; no Caddyfile workaround was needed.
- **The remaining failure is runtime-specific**: the assertion on `POST` answered in HTML fails in 6 of the 7 runtime runs on the private task (including batch 2, before these changes) and never on a baseline. The contract says GET and POST "behave the same"; agents put the HTML negotiation in the GET query and write a separate JSON-only POST command. The query/command split seems to invite treating the two methods as two endpoints, where the baselines share one handler. One run per configuration: the cost range is wide and needs more runs before a firm number.

After this batch, `method` accepts a list (`"method": ["GET", "POST"]`: one operation, one handler served identically for each method), and the guide says to use it when two methods must behave the same. Three more runs on that framework:

| Run | Score | Turns | Wall time | Notional cost | Cache read | Authored lines |
| --- | --- | --- | --- | --- | --- | --- |
| 164458 | 108/108 | 32 | invalid (laptop suspended mid-run) | $0.16 | 2.27M | 427 |
| 180731 | 108/108 | 27 | 290 s | $0.16 | 2.05M | 363 |
| 181239 | 108/108 | 26 | 247 s | $0.08 | 1.24M | 368 |

All three reach the maximum with no Caddyfile workaround. One uses `method: ["GET", "POST"]`; another keeps two operations but answers HTML from the POST command through `ctx.response`. Over the five runs on the fixed framework the cost is $0.08–0.18, median $0.16, against $0.51 before (−69% at the median); still 3–5× Go, Next.js and Rust, below API Platform ($0.22).

## Batch 5: three runs per stack, Haiku and Sonnet, and a first non-Claude model (2026-10-20, private task)

Every stack now has three runs of the private task per model on the current framework (the runtime's Haiku runs are the three of batch 4b). Scores are out of 108 (the excluded callback assertion is ignored; Sonnet passed it on 3 runs). Ranges are min–max over the three runs.

| Model | Stack | Scores | Turns | Notional cost | Wall time | Authored lines |
| --- | --- | --- | --- | --- | --- | --- |
| Haiku | runtime | 108, 108, 108 | 26–32 | $0.08–0.16 | 247–290 s (one run invalid, suspended) | 363–427 |
| Haiku | Go | 108, 108, 108 | 12–18 | $0.03–0.04 | 168–208 s | 652–794 |
| Haiku | Next.js | 108, 108, 108 | 13–19 | $0.03 | 227–343 s | 550–602 |
| Haiku | Rust | 108, 108, 108 | 20–28 | $0.05–0.06 | 329–440 s | 725–776 |
| Haiku | API Platform | 108, 100, 108 | 31–74 | $0.18–0.34 | 334–411 s | 761–821 |
| Sonnet | runtime | 108, 108, 108 | 9–17 | $0.30–0.40 | 106–149 s | 204–224 |
| Sonnet | Go | 88, 108, 88 | 7–8 | $0.14–0.18 | 82–92 s | 375–460 |
| Sonnet | Next.js | 108, 108, 108 | 5–10 | $0.13–0.15 | 98–109 s | 166–175 |
| Sonnet | Rust | 108, 108, 108 | 6–10 | $0.16–0.17 | 163–232 s | 178–437 |
| Sonnet | API Platform | 108, 108, 108 | 7–15 | $0.17–0.21 | 81–104 s | 236–350 |
| DeepSeek v4.1 flash | runtime | 108 | 68 | ~$0.15–0.30 (Model Studio prices; the CLI figure is meaningless) | 847 s | 506 |

- **With Haiku the runtime costs 3–5× Go, Next.js and Rust, and half of API Platform; with Sonnet about 2× every other stack.** Sonnet needs few turns everywhere, so the runtime's discovery overhead weighs less in relative terms but remains.
- **The code advantage depends on the model.** With Haiku the runtime writes about half the lines of any other stack. With Sonnet, Next.js writes less (166–175 lines against 204–224) and Rust and API Platform come close: a stronger model writes compact code in a stack it knows, and the declarative definitions stop being the shortest path.
- **Sonnet's Go failures are the agent's**, not the stack's: two of three runs configure pgx with `QueryExecModeSimpleProtocol` and pass the callback JSON as `[]byte`, which pgx then sends as a `bytea` literal that the `jsonb` column rejects (`invalid input syntax for type json`), so every callback call is a 500. The passing run keeps pgx's default mode.
- **A metric bug was fixed on the way**: two Sonnet API Platform runs created the Symfony project in `app/`, and root-anchored exclusions counted `app/public/bundles` and the skeleton as authored (3,128 and 3,197 lines instead of 236 and 305). Metrics now find each stack's project root (`eval/tests/metrics_test.sh`).
- **Non-Claude models** run through the same CLI against Alibaba Cloud Model Studio's Anthropic-compatible endpoint (`--provider alibaba`). DeepSeek v4.1 flash builds the runtime API at 108/108 with no prior knowledge of it, using `method: ["GET", "POST"]`, in 68 turns and 847 s with 5.8M cache-read tokens: discovery through the guide and the errors is not specific to Claude. Kimi k3 and Qwen 3.8 max could not finish on the account's free quota (1M tokens covers about half a runtime run, cache reads included) and are excluded.

## Batch 6: a JSON:API contract and agent context (v3) (2026-10-21, Haiku, bookshop)

**Why v3.** The plain-JSON contract of H7 never exercised what API Platform is for: no format negotiation, no relationships, no compound documents, no self-describing documentation. A first v3 draft asked for Hydra and was dropped: nothing but API Platform implements Hydra on the server side, so the comparison would have been API Platform against hand-written code. JSON:API has 118 server implementations listed on jsonapi.org, few of them maintained and complete (Elide, AshJsonApi, Graphiti, laravel-json-api, DRF-JSONAPI); in JavaScript there is only `@klerick/json-api-nestjs` (56 downloads a week).

v3 (`eval/v3/spec.md`) keeps v2's business rules and seed and puts them behind JSON:API 1.1 and an OpenAPI document on `/openapi.json`. Where the standard is open, the choices are neutral:

- filters are flat keys (`filter[minPrice]`);
- the total sits in `meta` under a key of the agent's choice, read by pattern (`total|count`);
- the OpenAPI document may be 3.0 or 3.1;
- links may be absolute or relative, and a 400 may name `filter[minPrice]` or just `filter`;
- `filter` and `page` may be declared as individual OpenAPI parameters or as one object parameter.

The scorer runs 275 HTTP assertions plus a validator (6 checks): `openapi-spec-validator` for the OpenAPI document and the JSON:API 1.0 JSON Schema, patched in memory for the 1.1 additions (jsonapi.org serves no schema any more, so the schema is vendored), in a container with no network. The CSV report is parsed per RFC 4180 instead of compared as text.

**References, "as designed".** One reference per framework, built to use it as its documentation intends, all 281/281 through `score.sh`:

| Framework | Authored lines | Main frictions |
| --- | --- | --- |
| API Platform 5.0.4 | ~985 PHP (+160 YAML/shell) | `composer require api` does not install JSON:API; the `id` property is emitted as `_id`; no query validation by default, and parameter violations are forced to 422 (400 needs a provider and a normalizer decorator); no built-in sort in request order or scalar comparison filter for JSON:API; 422 and error bodies need decorating; OpenAPI is 3.2 by default |
| Laravel 13 + laravel-json-api 5.3 | ~980 (+25 config) | the server must be registered by hand and generators fail until then; every request goes through the Gate (401 without policies); a malformed id gets a 401 from global middleware before routing; single fetch ignores the list scope (custom driver); a known filter with no validation rule is silently never applied; OpenAPI comes from a community package (last release 2025-01-31) and needs 92 lines of post-processing |
| Django 5.2 + DRF-JSONAPI 8.1 | ~715 | the built-in OpenAPI generator is deprecated and crashes with django-filter 26, drf-spectacular-jsonapi needs workarounds; errors lack `title`, body validation is 400, no `source.parameter`; pagination clamps instead of answering 400; django-filter accepts decimals and `m/d/Y` dates; DRF coerces `"2000"` to an int; Django 6.1 is blocked by a transitive dependency |

Even used as designed, each framework needs 700 to 1,000 lines and a list of workarounds to follow JSON:API strictly. Details: `research/apiplatform-v3-hydra-findings.md`, `research/laravel-v3-jsonapi-findings.md`, `research/django-v3-jsonapi-findings.md`.

**What agents are given.** Next.js (`create-next-app`) and Laravel (laravel-boost) ship an `AGENTS.md` in their scaffold. API Platform has an official, separate skillset: an `AGENTS.md` of 2.4k characters and a Claude Code plugin of 15 skills. NestJS, Django and DRF ship nothing (NestJS has an `llms.txt` that does not mention JSON:API). Claude Code does not load `AGENTS.md` alone; the skillset adds a `CLAUDE.md` containing `@AGENTS.md`.

The harness changed to measure this. The workdir is pre-scaffolded with the stack's official commands (`--scaffolded`, versions in `versions.txt`), and `--context none|agents-md|skillset` copies a context directory into it, switches project `CLAUDE.md` loading back on (`CLAUDE_CODE_DISABLE_CLAUDE_MDS=0`, verified) and loads the plugin offline with `--plugin-dir`. `api-platform.com` is allowed through the proxy for every API Platform condition, since the official `AGENTS.md` points at its documentation. Go and Rust are dropped, Laravel and Django are kept as references only, and NestJS runs on v3 only.

**Results.** Twelve runs, Haiku (`claude-haiku-5-5`), 120 turns and 60 minutes at most, scores out of 281. "API Platform use" is read from the archived code: `#[ApiResource]` with providers and processors and the native `jsonapi` format.

| Run | Context | Score | Turns | Notional cost | Wall time | Authored lines | API Platform use |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 124106 | none | 281 | 24 | $0.18 | 337 s | 1,788 | bypassed (controllers, hand-written OpenAPI) |
| 132432 | none | 281 | 39 | $0.35 | 479 s | 1,834 | bypassed |
| 140525 | none | 276 | 97 | $0.87 | 640 s | 1,732 | as designed (5 failures: `_id`, one empty filter) |
| 124715 | AGENTS.md | 281 | 117 | $1.15 | 874 s | 1,947 | empty `#[ApiResource]` shells over controllers, hand-written JSON:API layer |
| 133259 | AGENTS.md | 281 | 49 | $0.39 | 458 s | 1,911 | bypassed (7 documentation reads) |
| 141635 | AGENTS.md | 270 | 88 | $0.77 | 637 s | 1,837 | as designed (5 framework bugs, 4 × 401 after routing, 2 clean errors) |
| 130218 | skillset | 276 | 121 | $1.49 | 929 s | 1,736 | as designed (5 failures: `_id`, invalid OpenAPI 3.0) |
| 134105 | skillset | 281 | 99 | $1.28 | 938 s | 2,244 | partial (providers and processors, hand-written JSON:API) |
| 142739 | skillset | 278 | 130 | $1.29 | 837 s | 1,702 | as designed (invalid OpenAPI + 2 clean errors) |
| 131815 | NestJS, none | 281 | 44 | $0.19 | 335 s | 1,472 | everything by hand (`pg` only) |
| 135714 | NestJS, none | 281 | 54 | $0.32 | 449 s | 2,107 | everything by hand |
| 144208 | NestJS, none | 281 | 41 | $0.28 | 475 s | 1,434 | everything by hand |

- **Context pushes toward the framework, at a price.** Agents that use API Platform as designed: none 1 of 3, official `AGENTS.md` 1 of 3 (plus one run with empty `#[ApiResource]` shells over controllers), skillset 3 of 3 (one partial). The runs that use it cost $0.77 to $1.49, 3 to 5 times the $0.18 to $0.35 of the bypass and hand-written runs.
- **Bypassing the framework scores the maximum.** Every bypass and hand-written run reaches 281 at about $0.25. Using API Platform as designed loses 3 to 11 points, mostly to the JSON:API format bugs below (`_id`, OpenAPI).
- **The skills were never invoked**: 0 `Skill` calls over the 3 skillset runs. The skillset's effect comes from the `AGENTS.md`, followed through to `llms-full.txt`.
- **No difference in code size**: 1,700 to 2,200 lines everywhere. Using the framework does not reduce the code the agent writes.
- **n = 3 per cell**, one task, one model: these are tendencies, not rates.

**The OpenAPI failures, and a bias of the contract.** The contract allows "3.0 or 3.1". API Platform 5 emits 3.2 by default, so agents asked for `spec_version=3.0.0`. That output is not valid 3.0: it keeps `webhooks` (3.1 only; fixed on 4.4 by api-platform/core#8722, not merged up) and its JSON:API relationship schemas use `oneOf: [{type: 'null'}, ...]`, which only 3.1 allows. The contract's version restriction pushed the agents onto that path, so these failures are partly a bias of the contract, not only a framework defect.

**Upstream.** Opened today on api-platform/core after verifying each case against the code:

- #8747: pagination links duplicate the page parameter (`page[number]=2&number=2`) and `next` does not advance;
- #8748: validation errors carry no `status` or `title`, and `source.pointer` is not a JSON Pointer (no leading `/`);
- #8749: `_id` is an invalid member name (must not start with `_`);
- #8750: PATCH accepts a `data.id` or `data.type` that does not match the resource, where JSON:API requires 409.

Two candidates were dropped after verification: the missing `first`/`last` links on a single page (optional in the specification) and the top-level error `type` (duplicate of #8704, fixed on main by #8732).

### Batch 6b: a targeted AGENTS.md

`eval/contexts/apiplatform-targeted/AGENTS.md` (2,980 characters) gives the decision (resources with providers and processors, never controllers), a recipe per extension point, the JSON:API configuration and the pitfalls the references hit, with no link to the documentation. Three Haiku runs: 276, 278 and 277 out of 281, 77 to 121 turns (one hit the 120 cap), $0.93 to $1.80, 746 to 1,038 s, 1,413 to 1,787 authored lines. All three use API Platform as designed, like the skillset and unlike the official AGENTS.md (1/3) or no context (1/3). The cost does not drop: the tokens move from discovery to building. Failures are framework bugs (`_id`, the 3.0 downgrade of api-platform/core#8751, the top-level error `type` of #8704) and the agent's own mistakes (an empty filter value, a 500 on an unknown PATCH attribute, a 500 instead of 502 on a failing payment service, `webhooks: []` from an associative `json_decode`). The validator's 1.1 patch does not allow `links.type` on error objects; it changes no score since the top-level `type` fails the same check.

### Batch 7: the same conditions on a patched API Platform (2026-10-22, Haiku, bookshop)

**The fixes.** Six of the JSON:API bugs found in batch 6 were fixed upstream on api-platform/core: #8747 (PR #8753, pagination links), #8748 (PR #8755, error `status`/`title` and JSON Pointer), #8749 and #8750 (PR #8752, `_id` member name and PATCH 409), #8751 (PR #8754, the OpenAPI 3.0 downgrade) and #8756 (PR #8757, a JSON:API schema without readable properties giving `attributes.properties: []`, found while analysing these runs). They are on main, not released (5.1-dev); #8747, #8748, #8751 and #8756 are also on 5.0, the others wait for a later 5.0.x or 5.1.

**Pin.** The harness gets `--apip-ref <sha>`: the API Platform condition installs the monorepo `api-platform/core:dev-main#94c70a08a` in place of the split packages (same package set), with `strict_member_names: true` because the #8749 fix is opt-in; run directories carry the suffix `-patched`. The reference solution scores 281/281 against that version. `94c70a08a` predates the fix of #8756, so the 12 archived apps were re-scored against `253615b1a` (all six fixes) without rerunning any agent (`score-253615b1a.txt` in each run).

**Results.** Twelve runs, Haiku (`claude-haiku-5-5`), same task, contexts, limits and scorer as batch 6 (281 assertions), 4 conditions x 3. "Score" is at `94c70a08a`, "Re-score" at `253615b1a`.

| Run | Context | Score | Re-score | Turns | Notional cost | Wall time | Authored lines | API Platform use |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 121527 | none | 280 | 280 | 47 | $0.54 | 772 s | 1,574 | bypassed |
| 134011 | none | 276 | 276 | 32 | $0.30 | 468 s | 1,888 | bypassed (hand-written OpenAPI) |
| 143509 | none | 281 | 281 | 68 | $0.69 | 725 s | 2,213 | empty `#[ApiResource]` shells over controllers |
| 122946 | AGENTS.md | 278 | 278 | 115 | $1.35 | 2,885 s | 1,808 | as designed (1 controller) |
| 134902 | AGENTS.md | 280 | 280 | 73 | $0.68 | 750 s | 1,970 | empty `#[ApiResource]` shells over controllers |
| 144745 | AGENTS.md | 279 | 280 | 107 | $1.03 | 869 s | 2,038 | empty `#[ApiResource]` shells over controllers |
| 140206 | skillset | 279 | 279 | 93 | $1.30 | 1,021 s | 2,124 | as designed |
| 150243 | skillset | 281 | 281 | 67 | $0.46 | 536 s | 1,873 | empty `#[ApiResource]` shells over controllers |
| 153629 | skillset | 277 | 277 | 121 | $1.61 | 1,163 s | 1,717 | as designed (2 controllers), hit the turn cap |
| 132110 | targeted | 280 | 281 | 84 | $1.03 | 979 s | 1,672 | as designed |
| 141937 | targeted | 276 | 277 | 97 | $1.14 | 899 s | 1,493 | as designed |
| 151211 | targeted | 279 | 280 | 82 | $1.25 | 1,408 s | 1,759 | as designed |

The +1 of 141937 comes from the validator fix below, the +1 of 132110, 144745 and 151211 from the #8756 fix. Only FAIL lines disappear in a re-score.

**Against batches 6 and 6b**, per condition (API Platform used as designed, out of 3):

| Condition | Batch 6/6b (5.0.4) | Batch 7 (patched) |
| --- | --- | --- |
| none | 1/3 | 0/3 |
| official AGENTS.md | 1/3 | 1/3 |
| skillset | 3/3 | 2/3 |
| targeted AGENTS.md | 3/3 | 3/3 |

- Scores of the runs that use API Platform as designed: 270 to 278 on 5.0.4, 277 to 281 on the patched version (re-scored). The points lost to the framework drop from 4 or 5 per run to 0. What remains in those runs is the agents' own errors.
- Cost does not change: $1.03 to $1.61 as designed against $0.30 to $0.69 for the bypass runs (batch 6: $0.77 to $1.49 against $0.18 to $0.39). Code size does not change either (1,493 to 2,213 lines).
- Without context, the agents bypass the framework again, which the one as-designed run of batch 6 did not.

**The four OpenAPI documents still invalid** all come from agent code (checked by reproduction: changing only the agent's construction gives a valid document):

- 134902: `allowEmptyValue: false` passed positionally on path and header parameters (`src/OpenApi/Factory.php:72`, `Spec.php:123`);
- 140206: `properties: []` in its own JSON:API documentation (`JsonApiDocs.php:152`);
- 153629: `json_decode(..., true)` then `json_encode` turns `{}` into `[]` (`OpenApiController.php:54`, `:96`);
- 134011: OpenAPI written by hand.

On `253615b1a` the framework loses no point on this contract.

**Harness changes of the day.**

- Composer, npm and pip caches are shared between install containers only, after one scaffold failed on GitHub's unauthenticated rate limit (the `git clone` fallback is refused by the proxy). That run (131903) was deleted and redone.
- The validator allowed `links.type` on no error object. JSON:API 1.1 allows it, so it now does; the top-level `type` is still rejected. One run (141937) had lost a point to it.

**Caveats.** The patched runs use main (5.1-dev), which carries other JSON:API changes besides the six fixes, so the comparison with 5.0.4 is not only about these fixes. n = 3 per cell, one task, one model.

## Findings so far

From building the prototype, before any eval run:

- **MCP is absent exactly when it is needed** if an invalid definition stops the server. That led to the always-serve design; without it H2 would have been measured on a structurally biased setup.
- **Reading writes back through the query collides with visibility rules.** Creating an unpublished book returns 201 with `Location` and no body; a patch that hides a row returns 204 and the row is 404 afterwards. Consistent with API Platform (item queries filtered by extensions are 404 too), but agents will meet it.
- **Input and output shapes must be separate.** Without an input DTO the book resource had to expose `author_id` in its read shape just to be writable.
- **Hot reload must not execute external calls.** Validating non-SQL queries by running them called the external API on every save, under a lock; they are now only validated at runtime.
- **Starlark and TypeScript diverge on SQL safety.** TS has tagged templates; Starlark needed named placeholders (`ctx.sql("... {author}", author=x)`) to keep interpolation parameterised in both languages.
- **A fully blank agent is hard to prove.** The CLI's init event does not list loaded instruction files, so their absence rests on the container setup, not on evidence from the run.

### Findings of batches 6 and 7

- The plain-JSON contract of H7 hid the framework. A strict standard contract (JSON:API 1.1 plus a valid OpenAPI document) is reachable with every framework tested, but costs 700 to 1,000 lines as designed.
- Without context, agents bypass API Platform (controllers, hand-written OpenAPI) and score as high as anyone.
- A short targeted `AGENTS.md` that states the decision and the recipe gets designed use 3/3 in both batches; the official one, which points to the documentation, gets 1/3. The skills are never invoked.
- Designed use costs 2 to 3 times the bypass and the code size does not shrink.
- Framework bugs were the main score penalty for designed use. Six were fixed upstream; after that, designed use scores as well as the bypass (277 to 281).
- Few frameworks implement JSON:API or Hydra at all, which limits what can be compared.

## Threats to validity

- **Small sample and a single spec.** 3 runs per cell say little about variance; one spec says little about generality.
- **The spec is too easy**: every run of both models scores the maximum in under two and a half minutes, so it cannot separate variants. It will be made harder.
- **Same authors for guide, spec and scorer.** The guide may be unconsciously tuned to the spec. Tasks derived from an independent source (below) reduce that.
- **Corpus knowledge.** The API Platform test suite has been public for years (305 functional test files at `api-platform/core` `f3fce73a8`, 108 of them asserting Hydra collections), so models likely know the Hydra response shape. The eval does not depend on it: the scorer asks for JSON-LD itself and the spec never names a format.
- **One builtin plugin cannot be disabled** (`cc-plugin-sec-default`); its presence is recorded per run.
- **The scorer covers what it asserts.** Behaviour outside the 32 assertions is not measured.

## Next steps

- Propose the targeted `AGENTS.md` content to api-platform/skillset.
- Measure cost reduction ideas: a smaller recipe, discoverability through `debug:api-resource`, and other agent-facing improvements to API Platform.
- Fix the leaks (`-trimpath`, hide the MCP server command line) and rerun before drawing anything from the MCP effort gap.
- Force or randomise the script language so H5 gets data.
- A harder spec, and tasks derived from the API Platform functional test suite, limited for now to simple pagination and filters: each test becomes a plain-language spec plus scorer assertions taken from its asserts. The suite is a task source only, never context for the agent.
- Command-heavy tasks to make the script language decisive (H5), and a wazero engine to compare with goja if performance or isolation calls for it.
- H7, baseline comparison: the same API built by an agent with this runtime, Next.js and API Platform, compared on complexity, tokens consumed, amount of code written (excluding dependencies and generated code) and time to deliver. Run on both the current spec and the harder one: even the easy spec should expose the setup cost (scaffolding, ORM configuration) the study points at. The spec pins the JSON response contract, e.g. `{"items": [...], "total": 5}` for paginated lists, identical for every stack, as a real client would require: plain JSON has no pagination convention, so leaving the shape free would make scoring either lenient or unfair. That also tests format flexibility: this runtime must emit that shape instead of Hydra (built-in configurable JSON format, or the agent works it out from the guide), API Platform must be configured likewise. Package registry access (npm, Packagist) goes through the proxy for the baseline runs.
- H6, consumption by a blank client agent: Hydra/JSON-LD vs OpenAPI-only.
- More runs per cell (and Sonnet) to firm up the batch 4 cost figure.

## Reproduce

See [`README.md`](README.md): build with `xcaddy`, `go test ./...` against the compose Postgres, `eval/score.sh example` for the reference solution, `eval/run.sh --no-mcp|--mcp [--model M]` for a run, `eval/summarize.sh` for the table. A run needs a Claude OAuth token (`claude setup-token`) in `CLAUDE_CODE_OAUTH_TOKEN` or `~/.config/agentic-api-bench/eval-token` (mode 600).
