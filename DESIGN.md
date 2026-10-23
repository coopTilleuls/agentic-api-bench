# Prototype design

Throwaway prototype for the original study (not published). Goal: falsify "a blank agent produces a correct API on first try through discovery and error feedback". Everything below is a prototype decision, not a product decision.

## Decisions

- Go module `github.com/coopTilleuls/agentic-api-bench`, Caddy v2 HTTP handler `http.handlers.resource`, built with `xcaddy build --with github.com/coopTilleuls/agentic-api-bench=.`. Postgres via `jackc/pgx/v5` pool.
- Escape hatch runtime: `goja` + `esbuild` Go API, both in-process. TypeScript is transpiled by esbuild inside the binary and run in goja. This keeps single binary + agent writes TS, which the study attributes only to the WASM path: WASM needs a TS→WASM toolchain (javy, AssemblyScript) outside the binary, a worse loop for the agent. The engine sits behind a `ScriptEngine` interface so `wazero` can be measured later.
- Second engine: Starlark (`go.starlark.net`, Python syntax, pure Go, hermetic) to test "agents love Python". The script language is picked from the extension (`.ts` → goja, `.star`/`.py` → Starlark) and both expose the same `ctx` host API (snake_case is fine in Starlark: `ctx.db.query`, `ctx.fetch`). Starlark has no `import`, classes, f-strings or exceptions: each of those, when an agent tries it, must produce a teaching error (e.g. `import json` → "no imports, use ctx.json.decode"). The eval lets the agent pick and records which language it chose and whether it succeeded.
- Definitions are `.json` or `.ts` files in one directory. A `.ts` definition `export default defineResource({...})`, `defineResource` is identity injected by the host. The same JSON Schema validates both.

## Resource definition

```json
{
  "name": "Book",
  "path": "/books",
  "table": "book",
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "title": {"type": "string"},
    "author": {"type": "string", "filterable": true}
  },
  "query": "book.query.ts",
  "itemsPerPage": 30
}
```

`table` and `query` are mutually exclusive, exactly one required. Types: `string`, `integer`, `number`, `boolean`, `datetime`. Exactly one `identifier`.

## Query, the escape hatch

```ts
export default function query(ctx: QueryContext): Sql {
  return ctx.sql`SELECT b.id, b.title, a.name AS author
    FROM book b JOIN author a ON a.id = b.author_id
    WHERE b.published AND b.tenant = ${ctx.request.header("x-tenant")}`;
}
```

`ctx.sql` is a tagged template returning a parameterized fragment, interpolations become `$n` args, never string concat. `ctx.request` exposes `method`, `path`, `query(name)`, `header(name)`. The returned relation is composed by the host, so pagination, filters and item lookup apply to custom queries the same as to tables:

- collection: `SELECT * FROM (<q>) r WHERE <filters> ORDER BY r.<id> LIMIT $a OFFSET $b`, plus `SELECT count(*) FROM (<q>) r WHERE <filters>`
- item: `SELECT * FROM (<q>) r WHERE r.<id> = $1`

`table` is sugar for `SELECT <declared columns> FROM <table>`. One code path.

## Command, formats, external APIs

The escape hatch must cover writes, non-SQL sources and external HTTP, not only reads.

- Command: `POST` collection, `PUT`/`PATCH`/`DELETE` item. The resource (`properties`) is the output, there is no output DTO. Writes take an `input` block (same property syntax) declared on the resource or per operation, like an API Platform input DTO: the body is validated against `input` before the script runs (structured errors, `pointer` per field). Without `input`, a `table` resource accepts its non-`readOnly` properties. Default for `table` resources: generated `INSERT`/`UPDATE`/`DELETE`. Custom `"command": "book.command.ts"` exports one function per operation (`create`, `update`, `remove`), receives `(input, ctx)`, runs inside a transaction, returns the identifier. The response is read back through the resource's Query (CQRS: command writes, query reads). When the Query does not see the written row (visibility rules), `POST` returns 201 with `Location` and no body, `PUT`/`PATCH` return 204; the existence check before `PUT`/`PATCH`/`DELETE` uses the Query too, so rows the caller cannot read stay 404. `"readOnly": true` disables writes.
- Host API shared by Query and Command: `ctx.sql` (fragment), `ctx.db.query(sql)` / `ctx.db.exec(sql)` (same tx), `ctx.fetch(url, {method, headers, body})` returning `{status, headers, json(), text()}`, `ctx.request`, `ctx.env(name)` for secrets. `fetch` is denied unless the host is listed in the definition's `"allow": {"fetch": ["api.example.com"]}`; a denied call is a teaching error naming the key to add. Synchronous under the hood, but scripts may `await` it.
- Non-SQL Query: a Query may return an array of rows instead of a `Sql` fragment (external API, computed data). The host cannot compose those, so `ctx.page`, `ctx.itemsPerPage`, `ctx.filters`, `ctx.id` are passed in and the script returns `{items, totalItems}` for collections or one row / `null` for items. Returned rows are validated against `properties`.
- Output formats: reports commonly need something other than JSON. Content negotiation on `Accept` (and `?format=csv` / `.csv` suffix for links and browsers), `application/ld+json` (default), `application/json`, `text/csv`. A resource restricts its list with `"formats": [...]`, an unsupported `Accept` is a 406 teaching error listing the allowed ones. CSV is flat, header row = properties in declaration order, `Content-Disposition: attachment; filename=<path>.csv`. Collections in CSV are not paginated unless `?page` is given (a report export wants all rows), streamed row by row from the cursor rather than buffered. Serializers sit behind a `Format` interface so xlsx/pdf can be added later. Inputs stay JSON.
- Dedicated operations: in practice a report is often just an endpoint (`GET /business/export` → CSV), not a negotiated representation of a resource. A definition may declare `"operations"`, each with its own `method`, `path`, pinned `format` (no negotiation, `Accept` ignored), and its own `query`/`command`, e.g. `{"method": "GET", "path": "/business/export", "format": "csv", "query": "business_export.star"}`. `properties` describes the rows (CSV header, validation). A definition with only `operations` and no `table` is valid: it's a bag of endpoints sharing a row shape. Without `operations`, the default CRUD operations are generated as before. This mirrors API Platform's operation model (operation-level `uriTemplate`, `outputFormats`, provider/processor).

## Errors teach

Every error, at validation and at runtime, has the same shape:

```json
{"code": "unknown_column", "resource": "Book", "pointer": "/properties/author", "message": "column \"author\" not returned by query", "hint": "query returns: id, title, author_name. Alias the column (author_name AS author) or rename the property."}
```

Validation is static (schema, one identifier, table xor query, file exists, TS transpiles, default export is a function) then against the database (table exists, `SELECT * FROM (<q>) r LIMIT 0` succeeds, returned columns ⊇ declared properties, Postgres type compatible with declared type). Postgres errors are mapped to codes with a hint, never passed raw.

- `caddy resource validate --dir D --dsn X`: prints `{"valid": bool, "errors": [...]}`, exit 1 on errors.
- Provisioning failure inside `caddy run`/`caddy validate`: error string is the same JSON.
- HTTP runtime errors: `application/problem+json` with `code` and `hint` extensions.

## Always serve

The server starts whatever the state of the definitions. An invalid definition is served as its validation errors (problem+json, 500 on its declared paths, `code`/`pointer`/`hint` intact), valid ones keep working, and `/mcp` is always up with `validate` reporting the current errors. Definitions are reloaded from disk on change, so the agent's loop is edit → request, no restart. `caddy resource validate` stays the offline path.

## Self-description

- Data: JSON-LD/Hydra (unprefixed, API Platform 4 style: `member`, `totalItems`, `view`), `@id` IRIs, `/contexts/<Name>`, `GET /openapi.json` generated from the same metadata.
- Framework: `caddy resource guide` prints an agent guide (definition schema, query API, error codes, commands). `caddy resource init` scaffolds an example. `--help` points at `guide`.
- MCP at `/mcp`, JSON-RPC 2.0 over streamable HTTP (plain JSON responses, no SSE needed). Tools: `framework_guide`, `validate` (validates the loaded dir, or a definition passed inline), `list_resources`, and per resource `list_<name>` / `get_<name>`.

## Eval

Isolation: the eval agent must be blank. It runs in a Docker container (image with the `claude` CLI only), an empty `HOME`/`CLAUDE_CONFIG_DIR`, `/work` holding only the binary, no host mounts besides `/work`, network limited to Postgres and the stub, auth through `CLAUDE_CODE_OAUTH_TOKEN` or `ANTHROPIC_API_KEY` passed as env. Nothing from the host's CLAUDE.md, AGENTS.md, memory, plugins, skills or MCP servers may reach it. The run is rejected unless the stream-json `init` event shows no memory files, no plugins and only the expected MCP server (none for `--no-mcp`); the init event is saved with the transcript as proof.


`eval/` holds a functional spec (authors + books, list only published books with author name, filter by author, item lookup) that forces a custom `Query`, and a runner that puts a blank `claude -p` in an empty dir with only the binary and a running Postgres. Two variants: with and without `/mcp`. Scored by a fixed set of HTTP assertions, not by the model.

## v2 design (2026-10-19)

Driven by the gap list from writing `example-v2/` against `eval/v2/spec.md` with the v1 runtime. Goal: business SQL stays hand-written, the plumbing (pagination, count, sort, filter parsing, 400s, column lists, joins) is never hand-written.

### SQL first, metadata fragments (after esql)

Prior work: [soyuka/esql](https://github.com/soyuka/esql), native SQL with Doctrine-metadata-generated fragments and an alias-based row mapper. Hard for humans (interpolated helpers, alias conventions), a good fit for agents, which write SQL fluently and get the error-prone parts generated.

- Definitions may declare `relations`: `{"author": {"resource": "Author", "column": "author_id"}}` (to-one) and `{"books": {"resource": "Book", "foreignKey": "author_id", "many": true}}` (to-many). `table` resources describe their table, so metadata is known.
- `ctx.resource(name)` returns a helper: `table()` (`book AS book`), `alias()`, `column(prop)` (`book.title`), `columns(props?)` (`book.id AS book_id, ...`), `identifier()` (bound predicate on the id), `join(relation)` (`book.author_id = author.id`), `relation(name)` (helper for the related resource, aliased `book_author`). Fragments are inlined by `ctx.sql`, plain values are bound: injection-safe by construction. Same API in Starlark.
- Row mapping: a column aliased `<relation>_<prop>` for a declared relation fills a nested object (`author_id`, `author_name` → `author: {id, name}`); a `json`/`jsonb` column fills `object`/`array` properties as is (for `json_agg` of to-many relations).
- The host still wraps the query for parameters, pagination and count (one code path, unchanged principle).

### Parameters

One primitive for everything that maps an HTTP query or header value to the response, after API Platform's `QueryParameter`/`HeaderParameter`. Declared per resource or per operation:

```json
"parameters": {
  "title":         {"in": "query", "filter": "ipartial"},
  "author":        {"in": "query", "filter": "exact", "property": "author.name"},
  "priceCents":    {"in": "query", "filter": "range"},
  "publishedAt":   {"in": "query", "filter": "date"},
  "inStock":       {"in": "query", "type": "boolean", "handler": "in_stock.ts"},
  "order":         {"in": "query", "filter": "order", "properties": ["priceCents", "title", "publishedAt"]},
  "itemsPerPage":  {"in": "query", "type": "integer", "min": 1, "max": 50, "pagination": "itemsPerPage"},
  "Customer-Id": {"in": "header", "required": true, "status": 401, "handler": "customer.ts"}
}
```

- Parse and validate first (type, min/max, enum, required); failure answers 400, or the declared `status`. Header parameters with a `status` run before body validation.
- Built-in filters: `exact`, `partial`, `ipartial` (LIKE with `%`/`_` escaped), `range` (`[gt]`,`[gte]`,`[lt]`,`[lte]`), `date` (`[after]`,`[before]`, inclusive), `boolean`, `order` (multi-key in request order, id tiebreak, 400 on unknown property or direction). `property` may follow a relation (`author.name`).
- `pagination`: `page`, `itemsPerPage` (client-controlled page size within min/max). A definition sets `itemsPerPage` default and `order` default (`{"createdAt": "desc"}`).
- `handler`: a script `(value, ctx)` returning a SQL fragment (added to the WHERE), `null` (no-op), or `ctx.reject(...)`; it may also `ctx.set(name, value)` for the query to read via `ctx.get(name)`.
- Declared parameters are documented automatically in OpenAPI, the guide's per-resource listing and MCP tool schemas. Undeclared query parameters are ignored.

### Output side

- Formats configurable at site level in the Caddyfile and per resource: the JSON collection envelope (`{"items": "items", "total": "total", "page": "page", "itemsPerPage": "itemsPerPage", "pages": "pages"}`, any key may be omitted) and the error shape (`problem` (default), or `{"error": "message"}`). JSON-LD/Hydra stays the default format.
- Types `object` (with `properties`) and `array` (with `items`) for properties and `input`, nested validation with JSON pointers; type `date` (`YYYY-MM-DD`).
- `output` per operation: an explicit property list overriding the resource's when list and item shapes differ. The resource remains the default output.
- Writes look the item up with the query in `lookup` mode (`ctx.operation.phase == "lookup"`), separate from the read-back (`"read"`), so a query can see rows for writes that it hides for reads. Invisible read-back after `PATCH`/`PUT` answers 204.
- CSV and any streamed format run the query and fetch the first row before committing the status, so a `reject` or SQL error still produces the proper error status.
- Validation probe: a `ctx.reject` during the probe is not a definition error; `ctx.validating` is true during the probe.

### Implementation notes (2026-10-19)

Where the implementation departs from the bullets above, and why:

- `table` and `query` may now be combined: `table` describes the resource (column mapping, relations, `ctx.resource()` helpers, generated writes), `query` reads. Without it a query resource has no metadata, so helpers could not serve the resources that need custom SQL (all of them in `example-v2`). `table_and_query` is gone.
- Properties gained `column` (`priceCents` stored in `price_cents`) and `computed` (not a column, skipped by `columns()`): without a mapping, helpers would emit `book.priceCents`.
- `columns()` aliases root columns to the property name and relation columns to `<relation>_<prop>` (not `book_id` for the root as written above): the row mapper then needs no prefix stripping and nested objects come straight from the relation helper. Added `json(props?)` for `json_agg` of to-many relations; `column(name)` also accepts a plain column of the table (`published`).
- The phase is `ctx.phase` (`read`, `lookup`, `write`), not `ctx.operation.phase`: `ctx.operation` is a string in v1 scripts. `ctx.get` also returns parsed parameter values.
- Query parameters apply to collection reads only, header parameters to every operation (API Platform applies filters to collections too). Every parameter is parsed before body validation; those with a `status` first.
- `output` lives on operations, which may now end with `/{<identifier>}` (item operations: GET, PUT, PATCH, DELETE with lookup/read phases). Declaring operations also restricts methods. An operation without `format` negotiates instead of pinning JSON-LD.
- Input properties gained `nullable`, `min`, `max`, `minLength`, `maxLength`, `minItems`, `maxItems`, `enum`, and the definition `unknownInput: "ignore"` (the v2 spec ignores unknown PATCH keys; v1 keeps rejecting them).
- The configured error shape applies to every runtime error of a resource (4xx and 5xx, so a 502 from a failed `ctx.fetch` keeps the contract, Starlark having no try/catch); definition errors stay problem+json. Several input errors are joined in one message, each prefixed by its pointer.
- Without declared `parameters`, v1 behaviour is kept (`filterable` exact filters, `filter_not_enabled` on other property names); once `parameters` exist, undeclared query parameters are ignored.
- Shaped errors keep `code`, `hint`, `pointer` (and `errors`) as extra keys by default (`error_details off` / `"errorDetails": false` drops them). Handlers name outer columns with `ctx.property(name)`. A write's 404 whose row exists in `table` hints at `ctx.phase === "lookup"`. Without identifier, order and filters, the query is sent unwrapped so its `ORDER BY` holds.
