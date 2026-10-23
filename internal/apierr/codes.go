package apierr

const (
	InvalidConfig         Code = "invalid_config"
	DirNotFound           Code = "dir_not_found"
	NoResources           Code = "no_resources"
	InvalidJSON           Code = "invalid_json"
	UnknownField          Code = "unknown_field"
	MissingField          Code = "missing_field"
	InvalidType           Code = "invalid_type"
	InvalidValue          Code = "invalid_value"
	MissingIdentifier     Code = "missing_identifier"
	MultipleIdentifiers   Code = "multiple_identifiers"
	MissingSource         Code = "missing_source"
	DuplicateName         Code = "duplicate_name"
	DuplicatePath         Code = "duplicate_path"
	QueryFileNotFound     Code = "query_file_not_found"
	UnusedQueryFile       Code = "unused_query_file"
	TranspileError        Code = "transpile_error"
	InvalidExport         Code = "invalid_export"
	ScriptError           Code = "script_error"
	InvalidQueryResult    Code = "invalid_query_result"
	DatabaseChecksSkipped Code = "database_checks_skipped"
	DatabaseUnreachable   Code = "database_unreachable"
	UnknownTable          Code = "unknown_table"
	UnknownColumn         Code = "unknown_column"
	DuplicateColumn       Code = "duplicate_column"
	TypeMismatch          Code = "type_mismatch"
	QuerySyntaxError      Code = "query_syntax_error"
	PermissionDenied      Code = "permission_denied"
	QueryFailed           Code = "query_failed"
	InvalidParameter      Code = "invalid_parameter"
	MissingParameter      Code = "missing_parameter"
	NotFound              Code = "not_found"
	MethodNotAllowed      Code = "method_not_allowed"
	InvalidPage           Code = "invalid_page"
	InvalidFilterValue    Code = "invalid_filter_value"
	FilterNotEnabled      Code = "filter_not_enabled"
	InternalError         Code = "internal_error"
)

type Info struct {
	Status  int
	Summary string
	Fix     string
	Topic   string
}

var Registry = map[Code]Info{
	InvalidConfig:         {500, "The `resource` Caddyfile block is malformed.", "Use the options listed in the Caddyfile section (dir, dsn).", "server"},
	DirNotFound:           {500, "The definitions directory does not exist or is not a directory.", "Create it, or fix the `dir` option / --dir flag.", "server"},
	NoResources:           {500, "The definitions directory contains no definition file.", "Add a <Name>.json or <Name>.ts file (see `caddy resource init`).", "definitions"},
	InvalidJSON:           {500, "A .json definition is not valid JSON.", "Fix the syntax at the reported line/column.", "definitions"},
	UnknownField:          {500, "A key is not part of the definition schema.", "Remove it or use the suggested key; the schema is closed.", "definitions"},
	MissingField:          {500, "A required key is missing.", "Add the key named in the message.", "definitions"},
	InvalidType:           {500, "A value has the wrong JSON type.", "Use the type named in the message.", "definitions"},
	InvalidValue:          {500, "A value has the right type but is not allowed (enum, pattern, range).", "Use one of the allowed values given in the hint.", "definitions"},
	MissingIdentifier:     {500, "No property has `\"identifier\": true`.", "Mark exactly one property (usually id) as identifier.", "definitions"},
	MultipleIdentifiers:   {500, "More than one property has `\"identifier\": true`.", "Keep exactly one identifier.", "definitions"},
	MissingSource:         {500, "Neither `table` nor `query` is set.", "Set `table` for a plain table, or `query` for a custom query file.", "definitions"},
	DuplicateName:         {500, "Two definitions share the same `name`.", "Rename one of them.", "definitions"},
	DuplicatePath:         {500, "Two definitions share the same `path`.", "Change one of the paths.", "definitions"},
	QueryFileNotFound:     {500, "The file named by `query` does not exist next to the definition.", "Create it, or fix the file name (relative to the definitions directory).", "queries"},
	UnusedQueryFile:       {500, "A *.query.ts file is not referenced by any definition.", "Reference it with `query`, or delete it.", "queries"},
	TranspileError:        {500, "A .ts file does not compile.", "Fix the TypeScript error at the reported line/column.", "scripts"},
	InvalidExport:         {500, "A .ts file has no usable default export.", "Definitions: `export default defineResource({...})`. Queries: `export default function query(ctx) {...}`.", "scripts"},
	ScriptError:           {500, "A script threw an exception or timed out while running.", "Fix the script; the message carries the JavaScript error.", "scripts"},
	InvalidQueryResult:    {500, "A query or command function returned a value of the wrong kind.", "Queries return a ctx.sql fragment or rows; create returns the new identifier; operation commands return a row or nothing.", "queries"},
	DatabaseChecksSkipped: {500, "No dsn was given, so only static checks ran.", "Pass --dsn to also check tables, columns and types.", "server"},
	DatabaseUnreachable:   {503, "PostgreSQL could not be reached with the dsn.", "Check host, port, user, password and database name in the dsn.", "server"},
	UnknownTable:          {500, "A table referenced by `table` or by the query does not exist.", "The hint lists the existing tables.", "definitions"},
	UnknownColumn:         {500, "A declared property is not a column of the table or of the query result, or the query references a missing column.", "The hint lists the available columns. Alias query columns to the property name.", "queries"},
	DuplicateColumn:       {500, "The query returns two columns with the same name, a column reference is ambiguous, or two properties of a table resource map to the same column.", "Alias columns so every name is unique (a.id AS author_id), or set \"column\" on one of the properties.", "queries"},
	TypeMismatch:          {500, "A column's PostgreSQL type is incompatible with the declared property type.", "Change the declared type to the one suggested, or cast in the query (col::text).", "definitions"},
	QuerySyntaxError:      {500, "PostgreSQL rejected the query syntax.", "Fix the SQL at the reported line/column.", "queries"},
	PermissionDenied:      {500, "The database user may not read a table.", "Only read from tables the dsn user can SELECT from.", "queries"},
	QueryFailed:           {500, "PostgreSQL returned another error while running the query.", "Read the message and hint; usually a missing cast on an interpolated value.", "queries"},
	InvalidParameter:      {400, "A declared parameter has a malformed value (type, min/max, enum, unknown sort property or direction), or a request value could not be converted to a column type inside the query.", "Send a value matching the parameter declaration (the message says which); declare request values as parameters instead of parsing them in scripts.", "parameters"},
	MissingParameter:      {400, "A parameter declared `\"required\": true` is absent.", "Send it (query string or header, see `in`). The status is the parameter's `status` when declared (e.g. 401).", "parameters"},
	NotFound:              {404, "No route or no item matches the request.", "The hint lists the available paths; item lookups use /<path>/<identifier>.", "formats"},
	MethodNotAllowed:      {405, "The method is not served on this path.", "The hint lists the allowed methods. Writes need a `table` or a `command`; with `operations`, only the declared methods exist.", "operations"},
	InvalidPage:           {400, "The `page` query parameter is not a positive integer.", "Use ?page=1, ?page=2, ...", "parameters"},
	InvalidFilterValue:    {400, "A filter value cannot be converted to the property type.", "Send a value matching the property type (integer, number, true/false, RFC 3339 datetime).", "parameters"},
	FilterNotEnabled:      {400, "A query parameter names a property that is not filterable.", "Add `\"filterable\": true` to that property in the definition.", "parameters"},
	InternalError:         {500, "Unexpected server error.", "Report it; the message has details.", "errors"},

	CommandFileNotFound:    {500, "The file named by `command` does not exist next to the definition.", "Create it, or fix the file name.", "commands"},
	InvalidRow:             {500, "A query (or operation command) that returns rows instead of SQL returned a shape or a row that does not match the properties.", "Return {items, totalItems} for collections and one row object or null for items; each row needs every property with the declared type.", "queries"},
	FetchDenied:            {500, "ctx.fetch was called for a host the definition does not allow.", "Add the host (or host:port) to \"allow\": {\"fetch\": [...]} in the definition.", "scripts"},
	FetchFailed:            {502, "ctx.fetch could not reach the remote server or read its response.", "Check the URL and that the remote service is running.", "scripts"},
	EnvDenied:              {500, "ctx.env read a variable the definition does not allow.", "Add the variable name to \"allow\": {\"env\": [...]} in the definition.", "scripts"},
	UnsupportedMediaType:   {415, "The request body has a Content-Type the server does not read.", "Send a JSON object with Content-Type application/json, application/ld+json or application/merge-patch+json; CSV is an output format only.", "formats"},
	NotAcceptable:          {406, "The requested output format (Accept, ?format= or path suffix) is not allowed for this resource.", "Use one of the formats listed in the hint, or add the format to \"formats\" in the definition.", "formats"},
	InvalidBody:            {400, "The request body is not a JSON object.", "Send one JSON object per request.", "formats"},
	UnknownProperty:        {422, "The body contains a key that is not a property of the resource.", "Remove it; the hint lists the writable properties.", "commands"},
	ReadOnlyProperty:       {422, "The body sets a property declared `\"readOnly\": true`.", "Remove it from the body; read-only properties are computed by the query.", "commands"},
	RequiredProperty:       {422, "A property declared `\"required\": true` is missing on POST or PUT.", "Send it, or use PATCH for a partial update.", "commands"},
	InvalidPropertyValue:   {422, "A body value does not match the property type.", "Send the JSON type of the property (see the hint).", "commands"},
	ReadOnlyResource:       {405, "The resource does not accept writes.", "It is `\"readOnly\": true`, or it uses a `query` without a `command`: add a command file to make it writable.", "commands"},
	OperationNotSupported:  {405, "The command script does not define the function for this operation.", "Define `create` (POST), `update` (PUT/PATCH) or `remove` (DELETE) in the command script.", "commands"},
	Rejected:               {422, "A command rejected the input with ctx.reject().", "Read the message; it is a business rule of the API.", "commands"},
	Conflict:               {409, "A unique constraint rejected the write.", "Use a value that does not exist yet.", "commands"},
	InvalidReference:       {422, "A foreign key rejected the write: the referenced row does not exist.", "Reference an existing row (see the hint for the table).", "commands"},
	ConstraintViolation:    {422, "A NOT NULL or CHECK constraint rejected the write.", "Send a value for the column named by the pointer, or a value the constraint accepts.", "commands"},
	InvalidHelperCall:      {500, "A ctx.resource() helper was called with an unknown resource, property or relation, or on a resource without table.", "Use the names listed in the hint; declare relations in \"relations\" and give related resources a \"table\".", "queries"},
	InvalidCryptoCall:      {500, "A ctx.crypto helper got an unknown algorithm, malformed hex or base64, a size out of range, or decoded to bytes that are not UTF-8 text.", "Use sha256, sha512 or sha1; pass valid hex or base64 (standard or URL-safe alphabet, padding optional); randomHex takes 1 to 1024 bytes.", "scripts"},
	InvalidTimeValue:       {500, "ctx.time.parse got a string that is not an ISO 8601 date or datetime, or ctx.time.format got a value that is not a number of epoch seconds.", "Parse \"2024-01-31T12:00:00Z\", \"2024-01-31T12:00:00+02:00\", \"2024-01-31T12:00:00\" (UTC) or \"2024-01-31\"; format a number of seconds since 1970.", "scripts"},
	ResponseNotAllowed:     {500, "ctx.response was called where a raw response cannot be sent: outside a dedicated operation, in a parameter handler, or in the lookup phase of a write.", "Declare the endpoint in \"operations\" and call ctx.response from its GET query (collection or item, ctx.phase === \"read\") or from the command of a POST/PUT/PATCH/DELETE operation (item or not).", "scripts"},
	InvalidResponse:        {500, "ctx.response got an invalid argument: unknown key, a status outside 100-599, a body that is not a string, or a header name or value that is not valid.", "Call ctx.response({status, headers, body}) with an integer status, string-to-string headers and a string body.", "scripts"},
	NoDSN:                  {500, "A `caddy resource` command needs the database and found no dsn.", "Pass --dsn, or --config Caddyfile (a ./Caddyfile is read when present), or set DATABASE_URL.", "commands"},
	UsageError:             {400, "A `caddy resource` command got an argument it cannot use (unknown table, malformed header or argument).", "Read the message and the hint; `caddy resource <command> --help` lists the flags.", "commands"},
	DeprecatedHeaderPrefix: {500, "Warning: a header parameter is named with the `X-` prefix, deprecated by RFC 6648 for new headers. The definition stays valid and served.", "Name it without the prefix (`Customer-Id`, not `X-Customer-Id`), unless an existing client sends that header.", "parameters"},
}

const (
	CommandFileNotFound    Code = "command_file_not_found"
	InvalidRow             Code = "invalid_row"
	FetchDenied            Code = "fetch_denied"
	FetchFailed            Code = "fetch_failed"
	EnvDenied              Code = "env_denied"
	UnsupportedMediaType   Code = "unsupported_media_type"
	NotAcceptable          Code = "not_acceptable"
	InvalidBody            Code = "invalid_body"
	UnknownProperty        Code = "unknown_property"
	ReadOnlyProperty       Code = "read_only_property"
	RequiredProperty       Code = "required_property"
	InvalidPropertyValue   Code = "invalid_property_value"
	ReadOnlyResource       Code = "read_only_resource"
	OperationNotSupported  Code = "operation_not_supported"
	Rejected               Code = "rejected"
	Conflict               Code = "conflict"
	InvalidReference       Code = "invalid_reference"
	ConstraintViolation    Code = "constraint_violation"
	InvalidHelperCall      Code = "invalid_helper_call"
	DeprecatedHeaderPrefix Code = "deprecated_header_prefix"
	InvalidCryptoCall      Code = "invalid_crypto_call"
	InvalidTimeValue       Code = "invalid_time_value"
	ResponseNotAllowed     Code = "response_not_allowed"
	InvalidResponse        Code = "invalid_response"
	NoDSN                  Code = "no_dsn"
	UsageError             Code = "usage_error"
)
