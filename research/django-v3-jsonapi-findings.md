# Django + DRF-JSONAPI reference of eval/v3 (JSON:API), findings

2026-10-21. `eval/v3/reference/django/`, 275/275 on `assert.sh`, served OpenAPI 3.1 valid (openapi-spec-validator). ~715 authored lines (shop/ 560, settings+urls 94). Versions: Django 5.2.18 (6.1 blocked by drf-extensions 0.8.0 via drf-spectacular-jsonapi), DRF 3.18.3, djangorestframework-jsonapi 8.1.0, django-filter 26.2, drf-spectacular 0.30.0, drf-spectacular-jsonapi 0.6.2, psycopg 3.3.6, gunicorn 26.2.0.

Built in: managed=False models, `JSON_API_FORMAT_FIELD_NAMES=camelize`, `Meta.resource_name`, HyperlinkedModelSerializer gives links.self and Location, `ResourceRelatedField` + `included_serializers` for include, parser gives 409 on id/type mismatch, 415 on other content types.

Custom code needed:
- Built-in OpenAPI generator deprecated and crashes with django-filter 26 (`get_schema_operation_parameters`); drf-spectacular + drf-spectacular-jsonapi, which wraps error responses in `{data}` and crashes on non-$ref responses (`schemas/openapi.py:~321`), filter names snake_case, PATCH request schema marks fields required (package bug).
- Default auth classes Session/Basic leak into OpenAPI; empty them.
- Errors without `title`, body validation is 400 not 422, no `source.parameter`: custom exception handler.
- Pagination clamps page[size], 404 past last page: subclass.
- OrderingFilter: no id tie-break.
- `QueryParameterValidationFilter` 400s unknown params, `DjangoFilterBackend` 400s unknown `filter[x]` (spec: ignore).
- django-filter: NumberFilter accepts decimals, dates accept m/d/Y, BooleanFilter ignores junk.
- DRF coerces "2000" to int and 42 to str: strict fields.
- `created_at` DB default ignored on insert: `db_default=Now()`.
- `included` sorted by (type, string id).
- Unrouted paths give Django HTML 404: `handler404`.
- Scorer note: export STUB_PORT/STUB_QUOTES with STUB_URL/STUB_BIN when using a non-default stub port.
