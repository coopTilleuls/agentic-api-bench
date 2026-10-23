# API Platform 5: how to build an API here

This project uses API Platform 5 on Symfony. Build every endpoint with API Platform, not with Symfony controllers: a hand-written controller skips the serializer, validation, pagination, filters, error format and OpenAPI that API Platform gives you.

## Shape of the code

- A resource is a class marked `#[ApiResource]` with its operations (`Get`, `GetCollection`, `Post`, `Patch`, `Delete`). Map it to a Doctrine entity when the data is a table: the built-in Doctrine provider and processor then do read, write, pagination and filters.
- Existing database: map entities with `#[ORM\Table(name: ...)]` and `#[ORM\Column(name: ...)]`, never run migrations.
- Custom read: a `ProviderInterface` set as `provider:` on the operation. Custom write or side effects (external calls, several tables): a `ProcessorInterface` set as `processor:`, wrap it in a transaction. A provider or processor may return a Symfony `Response` (custom status, file download).
- Restrict what a request sees (soft-deleted rows, current user) with a Doctrine `QueryCollectionExtensionInterface` and `QueryItemExtensionInterface`.
- Input validation: Symfony constraints on the resource or on an `input:` DTO.
- Filters: `parameters: ['filter[title]' => new QueryParameter(filter: new PartialSearchFilter(), property: 'title')]` on the operation, `constraints:` on the parameter to validate it. Built-ins: `PartialSearchFilter`, `ExactFilter`, `RangeFilter`, `DateFilter`, `OrderFilter`. Write a `FilterInterface` for anything else.
- Inspect before guessing: `bin/console debug:api-resource`, the classes in `vendor/api-platform/metadata/`.

## Formats

- Configure in `config/packages/api_platform.yaml`: `formats`, `error_formats`, `docs_formats`, `defaults` (pagination, `normalization_context`).
- JSON-LD/Hydra is the default format, nothing to install.
- JSON:API: `composer require api-platform/json-api`, then `formats: {jsonapi: ['application/vnd.api+json']}` and the same in `error_formats`. The resource `type` is the `shortName`: set it and an explicit `uriTemplate` on each operation. Pagination parameters: `collection.pagination.page_parameter_name` / `items_per_page_parameter_name` (`page[number]` becomes `number`).
- OpenAPI is generated from the resources: `/docs.jsonopenapi`, add a route for another path (`controller: api_platform.action.documentation`, `defaults: {_format: jsonopenapi}`). Document extra headers with `HeaderParameter`.

## Pitfalls

- The recipe prefixes routes with `/api` (`config/routes/api_platform.yaml`).
- Null properties are skipped unless `normalization_context: {skip_null_values: false}`.
- Type errors in a body are 400 unless `defaults.collect_denormalization_errors: true` (422 with violations).
- Query parameter violations answer 422.
- With `APP_ENV=prod`, clear and warm the cache in the start script.
- `php -S`: pass `-d variables_order=EGPCS` or environment variables are ignored in favour of `.env`.
