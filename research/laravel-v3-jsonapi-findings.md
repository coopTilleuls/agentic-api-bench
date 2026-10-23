# Laravel + laravel-json-api reference of eval/v3 (JSON:API), findings

2026-10-21. `eval/v3/reference/laravel/`, 275/275 on `assert.sh`, validator 6/6 (OpenAPI 3.0 + JSON:API schema). ~980 authored lines + ~25 config lines. Versions: laravel/framework 13.38.0, laravel-json-api/laravel 5.3.0 (core 5.3.0, eloquent 4.7.0, validation 4.4.0, spec 3.3.0), OpenAPI via community swisnl/openapi-spec-generator 0.8.0 (last release 2025-01-31).

Notable: the Laravel 13 skeleton ships AGENTS.md/CLAUDE.md (laravel-boost guidelines). Our harness disables CLAUDE.md loading, so a blank agent does not get them unless it opens them.

Built in: 415, 409 on id mismatch, 422 pointers, JsonApiRule validators, PagePagination with id tie-break, Where/Scope filters.

Custom code / gotchas:
- `config/jsonapi.php` servers empty: register `v1`; artisan generators fail until then, and fail again once bootstrap references missing classes.
- `/api` prefix: `Server::$baseUri='/'`, `apiPrefix: ''`.
- Default authorization: every request goes through Gate, 401 without policies (`Authorizer.php:215`): `authorizable()` false.
- 401 before routing for malformed ids: global middleware (route constraints run before route middleware).
- `indexQuery` scopes lists only; single fetch uses `Repository::find()`: custom Driver.
- Pagination off unless `page` sent: `$defaultPagination`.
- `JsonApiRule::filter()` 400s unknown filters; a known filter without a validation rule is silently never applied; unvalidated unknown key → 500 (`FilterApplicator.php:141`).
- Default sort allows `id`.
- `DateTime` field emits timestamps for a date column: use Str.
- Relationship linkage only when included: `alwaysShowData()`.
- Unknown PATCH attribute → 400 (private validator): container rebinding.
- Query errors `source.parameter` dotted (`page.size`): translator override.
- Empty-message HttpExceptions have no `detail`.
- OpenAPI generator: no route, needs seeded DB to build examples, misses include/fields/headers/status codes/non-JSON:API routes: 92 lines of post-processing.
