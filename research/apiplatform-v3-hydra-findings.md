# API Platform reference of eval/v3 (Hydra variant), findings

2026-10-21. Reference built "as designed" by a delegated agent in `eval/v3/reference/apiplatform/` (Symfony 7.4.21, API Platform 4.4.5 pinned with `api-platform/symfony:^4` + `api-platform/doctrine-orm:^4`; note `composer require api` now installs 5.0.4, what a blank agent gets). Score 250/287 at stop (purchase 502s during the scorer run not diagnosed, CSV quoting/empty header, entrypoint keys, OpenAPI 3.2 vs 3.1, unfinished). ~790 PHP lines + ~150 YAML/shell: as much code as the blank-agent bypass builds of the product contract (761–821).

[F] format-independent, [H] Hydra-specific.

1. [F] `composer require api` gives 5.0.4; skeleton + api lacks `symfony/http-client` and `symfony/security-bundle`.
2. [F] Recipe `/api` prefix: remove `prefix` in `config/routes/api_platform.yaml`.
3. [F] Prod cache stale with APP_DEBUG=0: start.sh must clear + warm up.
4. [F] `php -S` needs `PHP_CLI_SERVER_WORKERS` and `-d date.timezone=UTC`.
5. [F] Existing schema: underscore naming strategy, `dbal.server_version`, `#[ORM\Table]`, IDENTITY ok with serial.
6. [H] Entrypoint keys are `lcfirst(shortName)` (`Hydra/Serializer/EntrypointNormalizer.php:54`): singular, plural needs a decorator.
7. [H] `serializer.hydra_prefix: false` for unprefixed terms.
8. [F] `error_formats: {jsonproblem: [application/problem+json]}`; 4xx `type` is `/errors/NNN`.
9. [F] Null properties skipped by default: `defaults.normalization_context.skip_null_values: false` (per-resource context did not override in the test).
10. [F] `defaults.collect_denormalization_errors: true` to get 422 + violations for type errors instead of 400.
11. [F] PATCH merge-patch + 415 is default; writable fields via denormalization groups.
12. [F] Pagination: `itemsPerPage=0` silently empty, >max clamped, `abc` ignored; validation needs a `QueryParameter` with `Type('digit')` + `Range(1,50)`.
13. [F] Filters via `QueryParameter`: `PartialSearchFilter` (case-insensitive default), `ExactFilter` on `author.name`, Range/Date (inclusive); no built-in for a derived boolean (`stock > 0`): custom filter; a parameter without `property` is dropped from the Hydra search mapping (`Hydra/State/Util/SearchHelperTrait.php`).
14. [H] `SearchHelperTrait` only expands `getDescription()` (so `priceCents[gte]` shows) when `filter:` is a service id, not an instance; exposes extra operators.
15. [F] Sort in request order needs a single `order` parameter backed by an `OrderFilter` service (`OrderFilter.php:249`), validation with `Assert\Collection`.
16. [F] Parameter validation throws ValidationException → 422; 400 needs a provider decorator limited to GET (blanket mapping breaks the PATCH 422).
17. [F] Constraint pitfalls: `Collection`+`Optional` for array params, `Regex`/`Date` ignore empty strings (add NotBlank), no function calls in attribute args.
18. [F] Visibility/ownership via query extensions; `OrderExtension` skips default order when an orderBy exists, so the `id` tie-break needs an extension after FilterExtension (-16), registered with `autoconfigure: false` to avoid a double tag.
19. [F] Default order per resource via `order:`.
20. [F] `date_immutable` serializes as datetime: `#[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])]`; timestamps come out `+00:00`.
21. [F] Shapes via groups on getters, `#[SerializedName]`, different groups for embedded vs resource; non-resource embedded objects get `/.well-known/genid/` ids.
22. [F] Header auth 401 before read/validation: Security firewall (priority 8) with a custom authenticator returning problem+json; document with `HeaderParameter`.
23. [F] POST with `application/json` needs `inputFormats` listing json, else 415; input DTO with nullable ints + NotNull to get violations instead of type errors; custom constraint for "published book".
24. [F] Purchase processor: `wrapInTransaction`, pessimistic locks in id order, 409/402/502 via HttpExceptions; 201 + Location for free; 5xx detail hidden.
25. [F] PATCH answering 204 for unpublished: decorate the persist processor and return a `Response`.
26. [F] CSV: `CsvEncoder` quotes fields with spaces and emits an empty body without header when there are no rows: hand-built Response.
27. [F] Accept `application/ld+json` on a csv-only operation is 406; workaround: add that mime to the csv format.
28. [H] Link header and `/docs` negotiation default; OpenAPI emitted is 3.2.0, not 3.1.
29. [F] `/books/abc` 404, `page=abc|0` 400 by default.

Open: the scorer's `csv_is` compares raw text (RFC 4180 allows quoting): decide whether to parse CSV.

# JSON:API port (API Platform 5.0.4), 2026-10-21

Reference `eval/v3/reference/apiplatform/`, 275/275 on the JSON:API `assert.sh` (validator not yet run). ~985 PHP lines + ~160 YAML/shell. Format-independent findings above still apply (Hydra ones dropped).

Likely API Platform bugs (to verify and report upstream):
- Pagination links: `JsonApiProvider` hoists `page[number]`/`page[size]` into `number`/`size`, `IriHelper::createIri` then writes `...&page[number]=2&number=2` in links.
- Single page: `CollectionNormalizer::getPaginationData` / `AbstractCollectionNormalizer::getPaginationConfig` (`serializer/AbstractCollectionNormalizer.php:130`) emit only `links.self` (no first/last), `self` can be `/authors?`.
- 422: jsonapi `ConstraintViolationListNormalizer` emits `{detail, source.pointer}` without `status`/`title`, and the pointer lacks the leading slash (`data/attributes/stock`, RFC 6901 requires `/`).
- Default 4xx/5xx `title` is always "An error occurred".
- PATCH: document `data.id` vs path id not checked (JSON:API requires 409).
- OpenAPI 5.0 emits 3.2.0 (`openapi/OpenApi.php:26`); 3.0 only via `?spec_version=3.0.0` (`openapi/Serializer/SerializerContextBuilder.php:35`).

Frictions an agent must be told:
- `composer require api` (5.0) does not include JSON:API: add `api-platform/json-api`; also `symfony/http-client`, `symfony/security-bundle`.
- `php -S` on Alpine: `variables_order=GPCS`, env vars invisible to Symfony Runtime, silently falls back to `.env`: `-d variables_order=EGPCS`.
- `formats: {jsonapi: [application/vnd.api+json]}`, `error_formats`, `docs_formats` with extra mimes to avoid 406.
- `type` = `shortName` (`shortName: 'books'` + explicit `uriTemplate` everywhere); `id` property emitted as `_id` attribute (`ReservedAttributeNameConverter`).
- `pagination: {page_parameter_name: number, items_per_page_parameter_name: size}`.
- No query validation by default (unknown sort, `include=foo`, `page[size]=0` accepted): explicit `QueryParameter` constraints; `Length(min:1)` not `NotBlank`; stock `SortFilter` is one property per parameter, JSON:API `sort` hoisting only feeds legacy `OrderFilter`: custom sort filter; scalar comparison (`filter[minPrice]`) needs a custom filter (stock ones are array-shaped).
- Parameter violations → 422 forced by `ErrorProvider`; 400 + `source.parameter` needs a GET-only provider decorator + error normalizer decorator.
- `collect_denormalization_errors` reports type errors alone, validation of other fields skipped.
- Typed `list<Dto>` input gives empty propertyPath on scalar input; `mixed` + `All/Collection` constraints.
- `/openapi.json` route: `controller: api_platform.action.documentation`, `defaults: {_format: jsonopenapi, _api_respond: true}`.
- CSV: hand-built Response; `csv` format must list `application/vnd.api+json` to avoid 406.
- Firewall 401 is outside API Platform: hand-built JSON:API error.

More JSON:API format issues found by the validator (score.sh pipeline, 2026-10-21):
- `ReservedAttributeNameConverter` renames an exposed `id` property to `_id`: invalid JSON:API member name (must not start with `_`).
- Error objects carry top-level `type` and `links.type`: not JSON:API error object members (schema rejects them).
- `?spec_version=3.0.0` relabels the 3.2 document as 3.0.0 but keeps `webhooks` (3.1+ only): invalid 3.0 document.
- With `_id` removed, an included resource whose only attribute is null gets no `attributes` (skip_null_values default).

# Upstream status (2026-10-22)

All six fixes are merged on api-platform/core main (`253615b1a`); #8747, #8748, #8751 and #8756 are also on 5.0. None is released yet (main is 5.1-dev).

| Bug | Issue | PR |
| --- | --- | --- |
| Pagination links duplicate the page parameter, `next` does not advance | #8747 | #8753 |
| Validation errors without `status`/`title`, `source.pointer` not a JSON Pointer | #8748 | #8755 |
| `_id` invalid member name | #8749 | #8752 |
| PATCH with mismatching `data.id`/`data.type` not answered 409 | #8750 | #8752 |
| OpenAPI 3.0 downgrade: `type: null` in `oneOf` (on top of #8722 for `webhooks`) | #8751 | #8754 |
| JSON:API schema with no readable property gives `attributes.properties: []` (invalid OpenAPI) | #8756 | #8757 |

Dropped after verification: the missing `first`/`last` links on a single page (optional in the specification) and the top-level error `type` (#8704, fixed by #8732).

Re-scoring the 12 patched runs against `253615b1a` leaves 4 invalid OpenAPI documents, all caused by agent code, not the framework: 134902 `allowEmptyValue: false` passed positionally (`src/OpenApi/Factory.php:72`, `Spec.php:123`); 140206 `properties: []` in its own JSON:API documentation (`JsonApiDocs.php:152`); 153629 `json_decode(..., true)` then `json_encode` turns `{}` into `[]` (`OpenApiController.php:54`, `:96`); 134011 OpenAPI written by hand.
