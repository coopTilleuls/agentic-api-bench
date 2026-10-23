# Eval v3

`spec.md` is the task given to the agent. v3 is v2's business behaviour (same filters, sort, pagination, purchase flow, payment stub, CSV report) behind a JSON:API 1.1 representation: resource objects with relationships, compound documents (`include`), `page[...]`, `filter[...]` and `sort` parameters, error documents, `PATCH` and `POST` as JSON:API documents (`application/vnd.api+json`, 415 otherwise), `Location` on a 201, and an OpenAPI document on `/openapi.json`. Every stack runs it (`run.sh --spec v3`, same footers as v2).

## Database

The seed is v2's: `eval/v2/seed.sql` (`seed_dir v3` in `lib.sh`), and so are the payment stub and the expected reports. See `eval/v2/README.md`.

## Assertions

`assert.sh` keeps every v2 behavioural assertion translated to the new shapes (`data[].attributes`, ids as strings, `meta` total, `page[number]`/`page[size]`, `filter[...]`, `sort=-priceCents,title`, JSON:API bodies, error documents), and adds groups named by prefix:

| Group | What | Count |
| --- | --- | --- |
| `jsonapi:` | resource objects (`type`, string `id`, `links.self`), `first`/`last`/`prev`/`next` links keeping filters, sort, include and page size, compound documents | 22 |
| `errors:` | error document shape, status as a string, one error per invalid field | 10 |
| `openapi:` | `GET /openapi.json`: version, operations, the 11 `GET /books` parameters, `Customer-Id`, PATCH/POST body schemas, response codes and schemas | 33 |
| v2, translated | filters, sorting, pagination, authors (relationships, include), purchases, report (CSV parsed per RFC 4180), PATCH (plus the 415 and the 409 on a `data.id` mismatch), 400s with `source.parameter`, 422s with `source.pointer` | the rest |
| validator | `validate.py`, below | 6 |

About 275 `assert.sh` assertions plus the 6 of the validator.

Parameters that are not part of the contract by name are matched tolerantly:

- The total is read from `.meta` by `meta_total`: the first number, down to depth 2, whose key matches `total|count`, keys containing `page` last. No key name is assumed.
- IRIs may be absolute or relative: comparisons go through `path_of` (scheme and host stripped) and `qparams` (query as an object, percent-decoded), so link parameters are compared as sets, never as strings.
- A 400 may name the parameter (`filter[minPrice]`) or its family (`filter`) in `source.parameter`.
- The OpenAPI document may declare `filter[...]`/`page[...]` as individual parameters or as one `filter`/`page` parameter whose schema has those properties; body schemas are searched at any nesting depth (`$ref`, `allOf`, `oneOf`, `anyOf`, `items` followed, within the document).
- `error.status` is accepted as a number by every error assertion; `errors: status is a string` checks it once.

## Validator

The assertions save one document of each kind (a collection, an item, a compound document, an error document, the document returned by `POST /purchases`, the OpenAPI document) into `$ASSERT_OUT`. `score.sh` mounts a fresh host temp directory there (never the workdir) and then runs `validate.py` in the `llmapi-eval-validator` image (`eval/docker/validator`: Python with pinned `openapi-spec-validator` and `jsonschema`, built once on first use, `--network none`):

- the OpenAPI document is valid (3.0 or 3.1, by its `openapi` member);
- each JSON:API document is valid against `schemas/jsonapi.json`, with `jsonschema`'s Draft 2020-12 validator. Its `status` members must be strings there, whatever `assert.sh` accepts.

`schemas/jsonapi.json` is the JSON Schema of the json-api repository, `_schemas/1.0/schema.json` on `gh-pages` (commit af47518badf387775184872680b7f6fb4763ecda, 2024-05-19), downloaded on 2026-10-21 from https://raw.githubusercontent.com/json-api/json-api/gh-pages/_schemas/1.0/schema.json. https://jsonapi.org/schema (where it used to be served) answers 404 and the repository holds no 1.1 schema, so the 1.1 additions are patched in below. `validate.py` rewrites the schema's `patternProperties: {"": true}` (any member) to `"^"` in memory, because `jsonschema` ignores an empty pattern and then rejects every member under `additionalProperties: false`.

`validate.py` also adds the 1.1 members in memory so that a valid 1.1 document never fails: `jsonapi.ext` and `jsonapi.profile` (arrays of URIs), `describedby` in top-level, resource and relationship `links`, the `rel`, `title`, `type`, `hreflang` and `describedby` members of link objects, and `lid` on resource objects and identifiers.

The validator's PASS/FAIL lines are appended to the score output and the `score:` line counts them.

## Judgement calls

- A body without `data` (`{}`) is not tested: the spec does not say whether it is a 400 or a 422. The purchase 422 cases send `data.attributes` with the v2 invalid `lines`.
- The `first` link may omit `page[number]` (page 1 is the default); `last`, `prev` and `next` must carry it.
- `GET /openapi.json` is requested with `Accept: application/json, application/vnd.oai.openapi+json;q=0.9, */*;q=0.1`, not `application/vnd.api+json`: the spec does not give its media type.
- `sort` with an unknown field answers 400; so does `sort=id`, `id` not being a sortable field. An empty `sort=`, an invalid direction (not applicable to the `-` prefix) and nested include paths (`include=author.books`) are not tested.
- `include` is checked on `/books`, `/books/{id}` and `/authors/{id}` (an unknown path is 400, `include=author` on an author is unknown).
