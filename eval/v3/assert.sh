#!/usr/bin/env bash
# Fixed HTTP assertions for eval/v3/spec.md (JSON:API 1.1), against a running
# server, for every stack.
#
#   eval/v3/assert.sh <base-url> <workdir>
#
# v3 is v2's behaviour (same seed, same business assertions) behind a JSON:API
# representation: resource objects, relationships and compound documents,
# page[...] / filter[...] / sort / include parameters, error documents, PATCH
# and POST as JSON:API documents, an OpenAPI document on /openapi.json.
# Needs curl and jq (awk for the CSV). The database must be freshly reset from
# eval/v2/seed.sql (score.sh does it): reads run first on the seed, then writes
# whose effects later assertions account for. The payment stub
# (eval/docker/stub) is reached at $STUB_URL, its call log through GET/DELETE
# /_stub/payments; it is stopped and restarted with $STUB_BIN to test an
# unreachable service. The documents eval/v3/validate.py checks (JSON:API
# schema, OpenAPI) are saved in $ASSERT_OUT (default: a temporary directory).
# <workdir> is only inspected to report which script languages a runtime
# solution uses.
set -uo pipefail
BASE="${1:?usage: assert.sh <base-url> <workdir>}"
WORK="${2:?usage: assert.sh <base-url> <workdir>}"
STUB_URL="${STUB_URL:-http://127.0.0.1:18090}"
STUB_BIN="${STUB_BIN:-/opt/stub/stub}"
TMP="$(mktemp -d)"
OUT="${ASSERT_OUT:-$TMP/out}"
mkdir -p "$OUT"
PASS=0
FAIL=0

# req METHOD PATH [BODY] [HEADER...] -> sets STATUS, BODY, CTYPE, and keeps
# every response header in $TMP/headers. Accept defaults to
# application/vnd.api+json and Content-Type (when there is a body) to
# application/vnd.api+json; a HEADER with the same name replaces the default.
# Query strings are sent as written (curl globbing off for filter[...]).
req() {
	local method="$1" path="$2" data="${3:-}"
	shift 3 2>/dev/null || shift $#
	local accept='Accept: application/vnd.api+json' ctype='Content-Type: application/vnd.api+json' h
	for h in "$@"; do
		case "${h,,}" in
		accept:*) accept="" ;;
		content-type:*) ctype="" ;;
		esac
	done
	local args=(-g -s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X "$method" --max-time 15)
	[ -n "$accept" ] && args+=(-H "$accept")
	if [ -n "$data" ]; then
		[ -n "$ctype" ] && args+=(-H "$ctype")
		args+=(--data "$data")
	fi
	for h in "$@"; do args+=(-H "$h"); done
	STATUS="$(curl "${args[@]}" "$BASE$path" 2>/dev/null || echo 000)"
	BODY="$(cat "$TMP/body" 2>/dev/null)"
	CTYPE="$(grep -i '^content-type:' "$TMP/headers" 2>/dev/null | head -1 | cut -d' ' -f2- | tr -d '\r')"
}

check() {
	local name="$1"
	shift
	if "$@" >/dev/null 2>&1; then
		PASS=$((PASS + 1))
		printf 'PASS  %s\n' "$name"
	else
		FAIL=$((FAIL + 1))
		printf 'FAIL  %s  (status %s, body %.300s)\n' "$name" "$STATUS" "$(echo "$BODY" | tr '\n' ' ')"
	fi
}

status_is() { [ "$STATUS" = "$1" ]; }
ctype_is() { [[ "${CTYPE,,}" == "$1"* ]]; }
jsonapi_ct() { ctype_is application/vnd.api+json; }
json_ct() { [[ "${CTYPE,,}" == *json* ]]; }

# hdr NAME: first value of a response header of the last request.
hdr() { grep -i "^$1:" "$TMP/headers" 2>/dev/null | head -1 | cut -d: -f2- | tr -d '\r' | sed -e 's/^ *//'; }
# location_is ID: the Location header, host stripped, is /purchases/ID.
location_is() { [ "$(hdr location | sed -E 's|^[A-Za-z][A-Za-z0-9+.-]*://[^/?#]*||')" = "/purchases/$1" ]; }

# jq_is FILTER: evaluated on BODY with helpers: ids (resource ids of a list, as
# numbers), epoch (ISO 8601 UTC timestamp to seconds, null if not UTC ISO 8601),
# path_of (a URL without scheme and host), qparams (the percent-decoded query of
# a URL as an object), q(name) (qparams of the links member `name`),
# lpath(name) (its path), meta_total (the total under .meta, whatever its key:
# first number whose key matches total|count, down to depth 2, "page" keys
# last), inc(doc; type; id) (an included resource), is_error, srcparam(name),
# viol(field).
JQ_DEFS='
def ids: [.data[].id | tonumber];
def epoch: if type == "string" then (capture("^(?<d>[0-9]{4}-[0-9]{2}-[0-9]{2})T(?<t>[0-9]{2}:[0-9]{2}:[0-9]{2})(\\.[0-9]+)?(Z|[+-]00:?00)$") // null) | if . == null then null else ("\(.d)T\(.t)Z" | fromdateiso8601) end else null end;
def path_of: (if type == "object" then .href else . end) | if type == "string" then sub("^[A-Za-z][A-Za-z0-9+.-]*://[^/?#]*"; "") else null end;
def pdec: gsub("\\+"; " ") | gsub("%5[Bb]"; "[") | gsub("%5[Dd]"; "]") | gsub("%20"; " ") | gsub("%2[Cc]"; ",") | gsub("%27"; "\u0027") | gsub("%2[Bb]"; "+") | gsub("%25"; "%");
def qparams: path_of | split("?")[1:] | join("?") | (split("#")[0] // "") | split("&") | map(select(length > 0) | split("=") | {key: (.[0] | pdec), value: (.[1:] | join("=") | pdec)}) | from_entries;
def q($n): .links[$n] | if . == null then null else qparams end;
def lpath($n): .links[$n] | path_of | split("?")[0];
def totals: [.meta | (to_entries[]? | select(.value | type == "number")), (to_entries[]? | select(.value | type == "object") | .value | to_entries[]? | select(.value | type == "number"))] | map(select(.key | test("total|count"; "i")));
def meta_total: totals | ((map(select(.key | test("page"; "i") | not)) | first) // first) | .value;
def inc($d; $t; $i): [($d.included // [])[] | select(.type == $t and .id == $i)] | first;
def is_error: (.errors | type == "array" and length > 0) and ((.errors[0].title | type == "string" and length > 0) or (.errors[0].detail | type == "string" and length > 0));
def srcparam($p): .errors | any(.[]; (.source.parameter // "") as $s | $s == $p or $s == ($p | split("[")[0]));
def viol($f): (.errors | length > 0) and all(.errors[]; .source.pointer | type == "string") and any(.errors[]; .source.pointer == "/data/attributes/\($f)" or ($f == "lines" and (.source.pointer | startswith("/data/attributes/lines"))));
'
jq_is() { echo "$BODY" | jq -e "$JQ_DEFS $1" >/dev/null; }

# error_is STATUS: the status and the JSON:API error document shape.
error_is() { status_is "$1" && jsonapi_ct && jq_is "is_error and (.errors[0].status | tostring) == \"$1\""; }
# param_error PARAM: a 400 error whose source.parameter is PARAM (or its family).
param_error() { error_is 400 && jq_is "srcparam(\"$1\")"; }
# invalid_is FIELD: a 422 with an error whose source.pointer is the attribute FIELD.
invalid_is() { error_is 422 && jq_is "viol(\"$1\")"; }

ids_are() { status_is 200 && jq_is "ids == $1"; }
total_is() { jq_is "meta_total == $1"; }

# Bodies: pbook ID ATTRIBUTES (PATCH /books/ID), pu ATTRIBUTES (POST /purchases).
pbook() { printf '{"data": {"type": "books", "id": "%s", "attributes": %s}}' "$1" "$2"; }
pu() { printf '{"data": {"type": "purchases", "attributes": %s}}' "$1"; }

# csv_rows: RFC 4180 on stdin (CRLF or LF, quoted fields, doubled quotes,
# newlines in quoted fields) to a JSON array of rows.
csv_rows() {
	sed -e 's/\r$//' | awk '
		function esc(s) { gsub(/\\/, "\\\\", s); gsub(/"/, "\\\"", s); gsub(/\t/, "\\t", s); gsub(/\n/, "\\n", s); return s }
		BEGIN { inq = 0; field = ""; rec = ""; nf = 0 }
		{
			if (inq) field = field "\n"
			else if ($0 == "") next
			n = length($0)
			for (i = 1; i <= n; i++) {
				c = substr($0, i, 1)
				if (inq) {
					if (c == "\"") { if (substr($0, i + 1, 1) == "\"") { field = field "\""; i++ } else inq = 0 }
					else field = field c
				} else if (c == "\"") inq = 1
				else if (c == ",") { rec = rec (nf++ ? "," : "") "\"" esc(field) "\""; field = "" }
				else field = field c
			}
			if (!inq) { rec = rec (nf++ ? "," : "") "\"" esc(field) "\""; print "[" rec "]"; rec = ""; nf = 0; field = "" }
		}' | jq -sc .
}
# csv_is EXPECTED: the body parsed as CSV equals EXPECTED parsed the same way.
csv_is() { [ "$(printf '%s' "$BODY" | csv_rows)" = "$(printf '%s\n' "$1" | csv_rows)" ]; }

stub_log() { curl -s --max-time 5 "$STUB_URL/_stub/payments"; }
stub_clear() { curl -s -o /dev/null --max-time 5 -X DELETE "$STUB_URL/_stub/payments"; }
stub_stop() {
	pkill -f "^$STUB_BIN\$" 2>/dev/null
	for _ in $(seq 1 40); do curl -s -o /dev/null --max-time 1 "$STUB_URL/quotes.json" || return 0; sleep 0.25; done
	return 1
}
stub_start() {
	(nohup "$STUB_BIN" >/dev/null 2>&1 &)
	for _ in $(seq 1 40); do curl -s -o /dev/null --max-time 1 "$STUB_URL/quotes.json" && return 0; sleep 0.25; done
	return 1
}

# Saved bodies for comparisons across requests.
save() { echo "$BODY" >"$TMP/$1"; }
same_as() { [ "$(echo "$BODY" | jq -cS . 2>/dev/null)" = "$(jq -cS . "$TMP/$1" 2>/dev/null)" ]; }

# save_doc FILE [STATUS]: keeps the body of the last request in $OUT for
# validate.py when it answered STATUS (default 200).
save_doc() { [ "$STATUS" = "${2:-200}" ] && echo "$BODY" >"$OUT/$1"; }

C1='Customer-Id: 1'
C2='Customer-Id: 2'
C3='Customer-Id: 3'
C4='Customer-Id: 4'

echo "== OpenAPI"
req GET /openapi.json "" 'Accept: application/json, application/vnd.oai.openapi+json;q=0.9, */*;q=0.1'
check "openapi: GET /openapi.json is 200" status_is 200
check "openapi: served as JSON" json_ct
save_doc openapi.json
check "openapi: version is 3.0 or 3.1" jq_is '(.openapi | type == "string") and (.openapi | startswith("3.0") or startswith("3.1"))'
OPENAPI_DEFS='
def deref: . as $x | if type == "object" and has("$ref") then ($x["$ref"] | ltrimstr("#/") | split("/") | map(gsub("~1"; "/") | gsub("~0"; "~"))) as $p | ($root | getpath($p)) else $x end;
def op($re; $m): [.paths | to_entries[] | select(.key | test($re)) | .value | select(type == "object" and has($m)) | .[$m]] | first;
def deepnames($d): if $d <= 0 then [] else deref as $s | if ($s | type) != "object" then [] else (($s.properties // {}) | to_entries | map([.key] + (.value | deepnames($d - 1))) | add // []) + ([($s.allOf // [], $s.oneOf // [], $s.anyOf // [])[] | deepnames($d - 1)] | add // []) + (($s.items // null) | if . == null then [] else deepnames($d - 1) end) end end;
def jschemas: [(.content // {}) | to_entries[] | select(.key | test("json"; "i")) | .value.schema | select(. != null)];
def qnames($re; $m): ([.paths | to_entries[] | select(.key | test($re)) | .value] | first) as $pi | [(($pi.parameters // []) + ($pi[$m].parameters // []))[] | deref | select(.in == "query") | if (.name == "filter" or .name == "page") then (.name as $n | [$n] + ((.schema // {}) | deepnames(3) | map("\($n)[\(.)]"))) else [.name] end] | add // [];
'
# $root is bound by the caller: the whole document.
oa_is() { echo "$BODY" | jq -e ". as \$root | $JQ_DEFS $OPENAPI_DEFS $1" >/dev/null; }
for entry in 'GET|/books|^/books$' 'GET|/books/{id}|^/books/\\{[^}]+\\}$' 'GET|/authors|^/authors$' 'GET|/authors/{id}|^/authors/\\{[^}]+\\}$' 'GET|/purchases|^/purchases$' 'GET|/purchases/{id}|^/purchases/\\{[^}]+\\}$' 'GET|/reports/sales|^/reports/sales$' 'PATCH|/books/{id}|^/books/\\{[^}]+\\}$' 'POST|/purchases|^/purchases$'; do
	IFS='|' read -r method label re <<<"$entry"
	check "openapi: $method $label is described" oa_is "op(\"$re\"; \"${method,,}\") != null"
done
for name in 'filter[title]' 'filter[author]' 'filter[minPrice]' 'filter[maxPrice]' 'filter[publishedAfter]' 'filter[publishedBefore]' 'filter[inStock]' sort include 'page[number]' 'page[size]'; do
	check "openapi: GET /books declares $name" oa_is "qnames(\"^/books\$\"; \"get\") | index(\"$name\") != null"
done
for entry in 'GET /purchases|^/purchases$|get' 'GET /purchases/{id}|^/purchases/\\{[^}]+\\}$|get' 'POST /purchases|^/purchases$|post'; do
	IFS='|' read -r label re method <<<"$entry"
	check "openapi: $label declares the Customer-Id header" oa_is "op(\"$re\"; \"$method\") | [(.parameters // [])[] | deref | select(.in == \"header\" and (.name | ascii_downcase) == \"customer-id\")] | length > 0"
done
check "openapi: PATCH /books/{id} body is application/vnd.api+json with title, priceCents and stock" oa_is 'op("^/books/\\{[^}]+\\}$"; "patch") | .requestBody | deref | .content["application/vnd.api+json"].schema | select(. != null) | deepnames(6) | (index("title") != null) and (index("priceCents") != null) and (index("stock") != null)'
check "openapi: POST /purchases body schema mentions lines" oa_is 'op("^/purchases$"; "post") | .requestBody | deref | (.content // {}) | to_entries | any(.value.schema != null and (.value.schema | deepnames(6) | index("lines") != null))'
check "openapi: POST /purchases declares a 201 response" oa_is 'op("^/purchases$"; "post") | .responses | has("201")'
check "openapi: POST /purchases declares a 422 response" oa_is 'op("^/purchases$"; "post") | .responses | has("422")'
check "openapi: GET /books/{id} declares a 404 response" oa_is 'op("^/books/\\{[^}]+\\}$"; "get") | .responses | has("404")'
check "openapi: GET /books 200 response has a schema" oa_is 'op("^/books$"; "get") | .responses["200"] | deref | (.content // {}) | to_entries | any(.value.schema != null)'
check "openapi: GET /books/{id} 200 response has a schema declaring title and priceCents" oa_is 'op("^/books/\\{[^}]+\\}$"; "get") | .responses["200"] | deref | (.content // {}) | to_entries | any(.value.schema != null and (.value.schema | deepnames(6) | (index("title") != null) and (index("priceCents") != null)))'

echo "== Books: list, pagination"
req GET /books
check "books: list is 200" status_is 200
check "books: response is application/vnd.api+json" jsonapi_ct
check "books: total counts published books only (14)" total_is 14
check "books: 10 per page by default" jq_is '(.data | length) == 10'
check "books: id order by default, unpublished absent" jq_is 'ids == [1,2,4,5,6,7,10,11,12,13]'
check "books: item shape (type, id string, attributes, author linkage)" jq_is '.data[0] | .type == "books" and .id == "1" and .attributes.title == "A Wizard of Earthsea" and .attributes.priceCents == 1200 and .attributes.publishedAt == "1968-11-01" and .attributes.stock == 5 and .relationships.author.data == {"type": "authors", "id": "1"}'
check "books: without include, included is absent or empty" jq_is '(.included // []) | length == 0'
check "books: anonymous author linkage" jq_is '(.data[] | select(.id == "10") | .relationships.author.data) == {"type": "authors", "id": "4"}'
check "jsonapi: every book has type books and a string id" jq_is 'all(.data[]; .type == "books" and (.id | type == "string"))'
check "jsonapi: every book links.self is /books/<id>" jq_is 'all(.data[]; (.links.self | path_of) == "/books/\(.id)")'
check "jsonapi: first link is page 1" jq_is '(q("first") | .["page[number]"] // "1") == "1"'
check "jsonapi: last link is page 2" jq_is 'q("last")["page[number]"] == "2"'
check "jsonapi: next link is page 2" jq_is 'q("next")["page[number]"] == "2"'
check "jsonapi: no prev link on the first page" jq_is '.links.prev == null'
check "jsonapi: links point at /books" jq_is 'lpath("first") == "/books" and lpath("last") == "/books" and lpath("next") == "/books"'
save_doc collection.json

req GET '/books?page[number]=2'
check "books: page 2" jq_is 'ids == [14,16,17,18]' 
check "books: page 2 keeps the total" total_is 14
check "jsonapi: page 2 has prev page 1" jq_is 'q("prev")["page[number]"] == "1"'
check "jsonapi: page 2 has no next" jq_is '.links.next == null'
check "jsonapi: page 2 last is page 2" jq_is 'q("last")["page[number]"] == "2"'
req GET '/books?page[number]=3'
check "books: page past the last is 200" status_is 200
check "books: page past the last has empty data" jq_is '.data == []'
check "books: page past the last keeps the total" total_is 14
req GET '/books?page[size]=5&page[number]=3'
check "books: page[size]=5 page 3" jq_is 'ids == [14,16,17,18] and q("prev")["page[size]"] == "5" and q("prev")["page[number]"] == "2"'
check "books: page[size]=5 page 3 total" total_is 14
req GET '/books?page[size]=1&page[number]=14'
check "books: page[size]=1 last page" jq_is 'ids == [18]'
req GET '/books?page[size]=50'
check "books: page[size]=50 is allowed" jq_is '(.data | length) == 14 and q("last")["page[size]"] == "50"'
for bad in 0 51 abc -1 2.5; do
	req GET "/books?page[size]=$bad"
	check "books: page[size]=$bad is 400 with source.parameter" param_error 'page[size]'
done
req GET '/books?page[number]=abc'
check "books: page[number]=abc is 400 with source.parameter" param_error 'page[number]'
req GET '/books?foo=bar&filter[nope]=1'
check "books: unknown parameters and filter keys are ignored" jq_is 'ids == [1,2,4,5,6,7,10,11,12,13]'
req GET '/books?filter[title]=dune&page[size]=2&page[number]=2'
check "jsonapi: filtered page 2 members" jq_is 'ids == [6,11]'
check "jsonapi: filtered view next keeps filter and size" jq_is 'q("next") | .["filter[title]"] == "dune" and .["page[size]"] == "2" and .["page[number]"] == "3"'
check "jsonapi: filtered view prev keeps filter and size" jq_is 'q("prev") | .["filter[title]"] == "dune" and .["page[size]"] == "2" and .["page[number]"] == "1"'
check "jsonapi: filtered view first keeps filter and size" jq_is 'q("first") | .["filter[title]"] == "dune" and .["page[size]"] == "2" and (.["page[number]"] // "1") == "1"'
check "jsonapi: filtered view last keeps filter and size" jq_is 'q("last") | .["filter[title]"] == "dune" and .["page[size]"] == "2" and .["page[number]"] == "3"'
req GET '/books?filter[minPrice]=1000&sort=-priceCents&filter[inStock]=true&page[size]=3'
check "jsonapi: view next keeps range, sort and stock parameters" jq_is 'q("next") | .["filter[minPrice]"] == "1000" and .sort == "-priceCents" and .["filter[inStock]"] == "true" and .["page[size]"] == "3" and .["page[number]"] == "2"'
req GET '/books?include=author&sort=priceCents,-title&page[size]=2'
check "jsonapi: view next keeps include and a multi-key sort" jq_is 'q("next") | .include == "author" and (.sort | split(",") | sort) == ["-title", "priceCents"] and .["page[number]"] == "2"'

echo "== Books: filters"
req GET '/books?filter[title]=dune'
check "filter title: contains, case-insensitive" ids_are '[4,5,6,11,18]'
req GET '/books?filter[title]=DUNE&page[size]=50'
check "filter title: upper case query" ids_are '[4,5,6,11,18]'
req GET '/books?filter[title]=lilith%27s'
check "filter title: apostrophe" ids_are '[13]'
req GET '/books?filter[title]=%25'
check "filter title: % is a literal character" jq_is '.data == []'
check "filter title: % total is 0" total_is 0
req GET '/books?filter[title]=_'
check "filter title: _ is a literal character" jq_is '.data == []'
req GET '/books?filter[author]=Frank%20Herbert'
check "filter author: exact name" ids_are '[4,5,6,11,18]'
req GET '/books?filter[author]=Frank'
check "filter author: not a substring match" jq_is '.data == []'
req GET '/books?filter[minPrice]=1000&page[size]=50'
check "filter minPrice: 1000 included, 999 excluded" jq_is '(ids | index(5) != null) and (ids | index(14) != null) and (ids | index(12) == null) and (ids | index(10) == null)'
check "filter minPrice: total 12" total_is 12
req GET '/books?filter[maxPrice]=2000&page[size]=50'
check "filter maxPrice: 2000 included, 2001 excluded" jq_is '(ids | index(4) != null) and (ids | index(13) == null)'
check "filter maxPrice: total 11" total_is 11
req GET '/books?filter[minPrice]=1000&filter[maxPrice]=2000'
check "filter price range" ids_are '[1,2,4,5,6,7,14,16,17]'
req GET '/books?filter[publishedAfter]=2020-01-01&page[size]=50'
check "filter publishedAfter: bound included" ids_are '[10,11,12,13,17,18]'
req GET '/books?filter[publishedBefore]=2020-12-31&page[size]=50'
check "filter publishedBefore: bound included" jq_is '(ids | index(11) != null) and (ids | index(17) == null) and (ids | index(18) == null)'
check "filter publishedBefore: total 12" total_is 12
req GET '/books?filter[publishedAfter]=2020-01-01&filter[publishedBefore]=2020-12-31'
check "filter published range" ids_are '[10,11,12,13]'
req GET '/books?filter[inStock]=true&page[size]=50'
check "filter inStock=true: stock > 0" jq_is '([.data[].attributes.stock] | all(. > 0)) and (ids | index(2) == null)'
check "filter inStock=true: total 11" total_is 11
req GET '/books?filter[inStock]=false'
check "filter inStock=false: stock 0" ids_are '[2,6,12]'
req GET '/books?filter[title]=dune&filter[inStock]=true&filter[maxPrice]=2500&sort=-priceCents'
check "filters combined with sorting" ids_are '[11,4,5]'
req GET '/books?filter[title]=dune&sort=priceCents&page[size]=2&page[number]=2'
check "filters combined with pagination" jq_is 'ids == [4,11] and q("prev")["page[size]"] == "2" and q("prev")["filter[title]"] == "dune"'
check "filters combined with pagination: total 5" total_is 5
req GET '/books?filter[title]=dune&include=author&sort=-priceCents&page[size]=2'
check "filters combined with include" jq_is '. as $d | ids == [18,11] and ([.included[] | select(.type == "authors") | .id] | unique == ["2"])'
for bad in 'filter[minPrice]=abc' 'filter[maxPrice]=' 'filter[publishedAfter]=2020-13-01' 'filter[publishedBefore]=yesterday' 'filter[publishedAfter]=2020-02-30' 'filter[inStock]=yes'; do
	req GET "/books?$bad"
	check "filter $bad is 400 with source.parameter" param_error "${bad%%=*}"
done

echo "== Books: sorting"
req GET '/books?sort=priceCents'
check "sort priceCents asc, ties by id" ids_are '[10,12,5,14,1,7,16,2,6,17]'
req GET '/books?sort=priceCents&page[number]=2'
check "sort priceCents asc, page 2" ids_are '[4,13,11,18]'
req GET '/books?sort=-priceCents'
check "sort priceCents desc, ties by id ascending" ids_are '[18,11,13,4,17,2,6,16,1,7]'
req GET '/books?sort=title'
check "sort title asc, ties by id" ids_are '[1,12,6,4,18,5,16,7,13,17]'
req GET '/books?sort=-title&page[number]=2'
check "sort title desc, page 2" ids_are '[18,6,12,1]'
req GET '/books?sort=publishedAt'
check "sort publishedAt asc" ids_are '[4,1,2,5,14,6,7,16,10,12]'
req GET '/books?sort=-publishedAt&page[size]=4'
check "sort publishedAt desc, ties by id" ids_are '[17,18,11,12]'
req GET '/books?sort=priceCents,-title'
check "sort: several keys in the order given" ids_are '[10,12,14,5,7,1,16,2,6,17]'
req GET '/books?sort=title,-priceCents'
check "sort: keys reversed give another order" ids_are '[1,12,6,18,4,5,16,7,13,17]'
for bad in 'sort=stock' 'sort=-stock' 'sort=id' 'sort=title,nope'; do
	req GET "/books?$bad"
	check "$bad is 400 with source.parameter" param_error sort
done

echo "== Books: items, include"
req GET /books/4
check "book 4: 200" status_is 200
check "book 4: application/vnd.api+json" jsonapi_ct
check "book 4: shape" jq_is '.data | .type == "books" and .id == "4" and .attributes.title == "Dune" and .attributes.priceCents == 2000 and .attributes.publishedAt == "1965-08-01" and .attributes.stock == 10 and .relationships.author.data == {"type": "authors", "id": "2"}'
check "jsonapi: book 4 id is a string and links.self is /books/4" jq_is '(.data.id | type == "string") and (.data.links.self | path_of) == "/books/4"'
check "book 4: without include, included is absent or empty" jq_is '(.included // []) | length == 0'
save_doc item.json
save book4
req GET '/books/4?include=author'
check "book 4: include=author adds the author" jq_is '. as $d | inc($d; "authors"; "2") | .attributes.name == "Frank Herbert"'
check "book 4: include=author has no duplicate or unrelated resource" jq_is '(.included | length) == 1'
save_doc compound.json
req GET '/books?include=author&page[size]=50'
check "include=author on a list: every author linked is included once" jq_is '. as $d | ([.included[] | "\(.type)/\(.id)"] | (length == (unique | length))) and all($d.data[]; .relationships.author.data as $a | inc($d; $a.type; $a.id) != null)'
check "include=author on a list: anonymous author name is null" jq_is '. as $d | inc($d; "authors"; "4") | has("attributes") and .attributes.name == null'
check "include=author on a list: included authors link themselves" jq_is 'all(.included[]; (.links.self | path_of) == "/authors/\(.id)")'
req GET /books/10
check "book 10: anonymous author linkage" jq_is '.data.relationships.author.data == {"type": "authors", "id": "4"}'
req GET /books/3
check "unpublished book is 404 error document" error_is 404
check "errors: status is a string" jq_is '.errors[0].status | type == "string"'
save_doc error.json 404
check "errors: 404 has title and detail" jq_is '(.errors[0].title | type == "string" and length > 0) and (.errors[0].detail | type == "string" and length > 0)'
req GET /books/999
check "unknown book is 404 error document" error_is 404
req GET /books/abc
check "malformed book id is 404 error document" error_is 404
req GET '/books/4?include=publisher'
check "unknown include path is 400 with source.parameter" param_error include
req GET '/books?include=publisher'
check "unknown include path on a list is 400 with source.parameter" param_error include

echo "== Authors"
req GET /authors
check "authors: list" jq_is 'ids == [1,2,3,4,5]'
check "authors: total 5" total_is 5
check "jsonapi: authors have type authors, a string id and links.self" jq_is 'all(.data[]; .type == "authors" and (.id | type == "string") and (.links.self | path_of) == "/authors/\(.id)")'
check "authors: publishedBooks counts published books only" jq_is '[.data[].attributes.publishedBooks] == [4,5,4,1,0]'
check "authors: anonymous author has a null name" jq_is '.data[3].attributes.name == null and .data[0].attributes.name == "Ursula K. Le Guin"'
check "jsonapi: authors links, single page" jq_is 'lpath("first") == "/authors" and (q("last")["page[number]"] == "1") and .links.next == null and .links.prev == null'
req GET '/authors?page[size]=2&page[number]=3'
check "authors: paginated" jq_is 'ids == [5]'
check "authors: paginated total" total_is 5
req GET /authors/1
check "author 1: type, id, attributes" jq_is '.data | .type == "authors" and .id == "1" and .attributes.name == "Ursula K. Le Guin" and (.links.self | path_of) == "/authors/1"'
check "author 1: books relationship, published books in id order" jq_is '[.data.relationships.books.data[] | select(.type == "books") | .id | tonumber] == [1,2,14,17]'
check "author 1: without include, included is absent or empty" jq_is '(.included // []) | length == 0'
req GET '/authors/1?include=books'
check "author 1: include=books adds the books with their titles" jq_is '[.included[] | select(.type == "books") | {id: (.id | tonumber), title: .attributes.title}] | sort_by(.id) == [{"id":1,"title":"A Wizard of Earthsea"},{"id":2,"title":"The Left Hand of Darkness"},{"id":14,"title":"The Lathe of Heaven"},{"id":17,"title":"Tehanu"}]'
req GET /authors/4
check "author 4: anonymous, unpublished book hidden" jq_is '.data.attributes.name == null and [.data.relationships.books.data[].id] == ["10"]'
req GET /authors/5
check "author 5: no published book" jq_is '.data.attributes.name == "Isaac Asimov" and .data.relationships.books.data == []'
req GET /authors/99
check "unknown author is 404 error document" error_is 404
req GET /authors/abc
check "malformed author id is 404 error document" error_is 404
req GET '/authors/1?include=author'
check "unknown include path on an author is 400 with source.parameter" param_error include

echo "== Purchases: identification"
for h in '' 'Customer-Id: abc' 'Customer-Id: 99' 'Customer-Id: 1.5'; do
	label="${h:-no header}"
	req GET /purchases "" ${h:+"$h"}
	check "GET /purchases with $label is 401 error document" error_is 401
	req GET /purchases/1 "" ${h:+"$h"}
	check "GET /purchases/1 with $label is 401 error document" error_is 401
	req GET /purchases/abc "" ${h:+"$h"}
	check "GET /purchases/abc with $label is 401 error document (checked first)" error_is 401
	req POST /purchases "$(pu '{"lines": []}')" ${h:+"$h"}
	check "POST /purchases (invalid body) with $label is 401 error document (checked first)" error_is 401
done

echo "== Purchases: reads"
req GET /purchases "" "$C1"
check "customer 1: own purchases, most recent first" ids_are '[3,1,5,6]'
check "customer 1: total 4" total_is 4
check "jsonapi: purchases have type purchases, a string id and links.self" jq_is 'all(.data[]; .type == "purchases" and (.id | type == "string") and (.links.self | path_of) == "/purchases/\(.id)")'
check "purchase shape: createdAt (UTC ISO 8601), totalCents, lines" jq_is '.data[0].attributes | (.createdAt | epoch) == ("2026-02-01T00:00:00Z" | fromdateiso8601) and .totalCents == 3000 and (.lines | map({bookId, title, quantity, unitPriceCents})) == [{"bookId":5,"title":"Dune Messiah","quantity":3,"unitPriceCents":1000}]'
check "purchase timestamps parse" jq_is '[.data[].attributes.createdAt | epoch] == (["2026-02-01T00:00:00Z","2026-01-05T10:00:00Z","2026-01-01T00:00:00Z","2025-12-31T23:59:59Z"] | map(fromdateiso8601))'
check "jsonapi: purchases links, single page" jq_is 'lpath("first") == "/purchases" and (q("last")["page[number]"] == "1") and .links.next == null and .links.prev == null'
req GET '/purchases?page[size]=2&page[number]=2' "" "$C1"
check "customer 1: paginated" jq_is 'ids == [5,6] and q("prev")["page[size]"] == "2" and q("prev")["page[number]"] == "1" and .links.next == null'
check "customer 1: paginated total" total_is 4
req GET /purchases "" "$C4"
check "customer without purchases: empty list" jq_is '.data == []'
check "customer without purchases: total 0" total_is 0
req GET /purchases/1 "" "$C1"
check "purchase 1: lines with stored unit prices" jq_is '.data | .type == "purchases" and .id == "1" and (.links.self | path_of) == "/purchases/1" and .attributes.totalCents == 5200 and (.attributes.lines | map({bookId, title, quantity, unitPriceCents}) | sort_by(.bookId)) == [{"bookId":4,"title":"Dune","quantity":2,"unitPriceCents":2000},{"bookId":7,"title":"Kindred","quantity":1,"unitPriceCents":1200}]'
req GET /purchases/5 "" "$C1"
check "purchase 5: unit price as stored, not current" jq_is '(.data.attributes.lines | map({bookId, title, quantity, unitPriceCents})) == [{"bookId":1,"title":"A Wizard of Earthsea","quantity":1,"unitPriceCents":1100}]'
req GET /purchases/2 "" "$C1"
check "another customer's purchase is 404 error document" error_is 404
req GET /purchases/2 "" "$C2"
check "the owner sees it" jq_is '.data.id == "2" and .data.attributes.totalCents == 3200'
req GET /purchases/999 "" "$C1"
check "unknown purchase is 404 error document" error_is 404
req GET /purchases/abc "" "$C1"
check "malformed purchase id is 404 error document" error_is 404

echo "== Sales report"
req GET '/reports/sales?from=2026-01-01&to=2026-01-31'
check "report January: 200" status_is 200
check "report: served as CSV" ctype_is text/csv
check "report January: content" csv_is "$(printf 'title,quantity,revenueCents\nDune,3,6000\nA Wizard of Earthsea,2,2300\nKindred,1,1200\nLilith'"'"'s Brood,1,1200')"
req GET '/reports/sales?from=2026-02-01&to=2026-02-28'
check "report February: groups by book, not title" csv_is "$(printf 'title,quantity,revenueCents\nDune,1,3500\nDune Messiah,3,3000\nThe Road to Dune,1,2500\nKindred,2,2400\nDune,1,2000\nThe Anonymous Chronicle,1,800')"
req GET '/reports/sales?from=2025-12-31&to=2025-12-31'
check "report: last second of a day included" csv_is "$(printf 'title,quantity,revenueCents\nThe Left Hand of Darkness,1,1500')"
req GET '/reports/sales?from=2026-02-01&to=2026-02-01'
check "report: first second of a day included" csv_is "$(printf 'title,quantity,revenueCents\nDune Messiah,3,3000')"
req GET '/reports/sales?from=2030-01-01&to=2030-12-31'
check "report: empty period is 200" status_is 200
check "report: empty period is the header only" csv_is 'title,quantity,revenueCents'
for bad in '' 'from=2026-01-01' 'to=2026-01-31' 'from=2026-13-01&to=2026-12-31' 'from=2026-01-01&to=soon' 'from=2026-02-30&to=2026-03-01' 'from=2026-02-01&to=2026-01-31'; do
	req GET "/reports/sales?$bad"
	check "report ?$bad is 400 error document" error_is 400
done

echo "== Books: PATCH"
req PATCH /books/4 "$(pbook 4 '{"title": "Dune (1965)"}')"
check "patch title: 200" status_is 200
check "patch title: application/vnd.api+json" jsonapi_ct
check "patch title: book document, only title changed" jq_is '.data | .type == "books" and .id == "4" and .attributes.title == "Dune (1965)" and .attributes.priceCents == 2000 and .attributes.stock == 10 and .attributes.publishedAt == "1965-08-01" and .relationships.author.data == {"type": "authors", "id": "2"} and (.links.self | path_of) == "/books/4"'
req PATCH /books/4 "$(pbook 4 '{"priceCents": 2100, "isbn": "978-0441013593", "author": {"id": 1}}')"
check "patch: unknown attributes ignored" jq_is '.data.attributes.priceCents == 2100 and .data.attributes.title == "Dune (1965)" and .data.relationships.author.data.id == "2"'
req PATCH /books/4 "$(pbook 4 '{"stock": 0}')"
check "patch: stock 0 allowed" jq_is '.data.attributes.stock == 0'
req PATCH /books/4 "$(pbook 4 '{"stock": 10, "priceCents": 0}')"
check "patch: several attributes, price 0 allowed" jq_is '.data.attributes.stock == 10 and .data.attributes.priceCents == 0'
req PATCH /books/4 "$(pbook 4 '{"priceCents": 2100}')"
req GET /books/4
save book4
for bad in '{"priceCents": -1}|priceCents' '{"stock": -1}|stock' '{"stock": 1.5}|stock' '{"priceCents": "2000"}|priceCents' '{"stock": null}|stock' '{"title": null}|title' '{"title": ""}|title' '{"title": "Ok", "stock": -5}|stock' '{"title": 42}|title'; do
	req PATCH /books/4 "$(pbook 4 "${bad%|*}")"
	check "patch ${bad%|*} is 422 with a /data/attributes/${bad##*|} pointer" invalid_is "${bad##*|}"
done
req GET /books/4
check "rejected patches changed nothing" same_as book4
req PATCH /books/4 "$(pbook 4 '{"stock": 11}')" 'Content-Type: application/json'
check "patch as application/json is 415 error document" error_is 415
req GET /books/4
check "patch as application/json changed nothing" same_as book4
req PATCH /books/4 "$(pbook 5 '{"stock": 11}')"
check "patch with another data.id than the path is 409 error document" error_is 409
req GET /books/4
check "patch with another data.id changed nothing" same_as book4
req PATCH /books/3 "$(pbook 3 '{"stock": 7, "title": "The Dispossessed"}')"
check "patch on an unpublished book is 204" status_is 204
req GET /books/3
check "unpublished book stays hidden" error_is 404
req PATCH /books/999 "$(pbook 999 '{"stock": 1}')"
check "patch unknown book is 404 error document" error_is 404
req PATCH /books/abc "$(pbook abc '{"stock": 1}')"
check "patch malformed id is 404 error document" error_is 404

echo "== Errors"
req GET /books/999
check "errors: 404 is application/vnd.api+json" jsonapi_ct
check "errors: errors is a non-empty array of objects" jq_is '(.errors | type == "array" and length > 0) and all(.errors[]; type == "object")'
check "errors: status is the HTTP status" jq_is '(.errors[0].status | tostring) == "404"'
check "errors: title is a non-empty string" jq_is '.errors[0].title | type == "string" and length > 0'
check "errors: detail is a non-empty string" jq_is '.errors[0].detail | type == "string" and length > 0'
req PATCH /books/4 "$(pbook 4 '{"priceCents": -1, "stock": -1, "title": ""}')"
check "errors: 422 has one error per invalid field" jq_is '(.errors | length) >= 3 and ([.errors[].source.pointer] | (index("/data/attributes/priceCents") != null) and (index("/data/attributes/stock") != null) and (index("/data/attributes/title") != null))'
check "errors: every 422 error has status 422" jq_is 'all(.errors[]; (.status | tostring) == "422")'
req GET '/books?page[size]=0'
check "errors: 400 names the parameter in source.parameter" jq_is '.errors[0].source.parameter | type == "string" and length > 0'

echo "== Purchases: failures change nothing"
stub_clear
req GET /purchases "" "$C1"
check "customer 1 has 4 purchases before" total_is 4
for bad in '{}' '{"lines": []}' '{"lines": "x"}' '{"lines": [{"bookId": 3, "quantity": 1}]}' '{"lines": [{"bookId": 999, "quantity": 1}]}' '{"lines": [{"bookId": 1, "quantity": 0}]}' '{"lines": [{"bookId": 1, "quantity": 1.5}]}' '{"lines": [{"bookId": 1, "quantity": "2"}]}' '{"lines": [{"bookId": 1}]}' '{"lines": [{"quantity": 1}]}' '{"lines": [{"bookId": 1, "quantity": 1}, {"bookId": 8, "quantity": 1}]}'; do
	req POST /purchases "$(pu "$bad")" "$C1"
	check "purchase $bad is 422 with a /data/attributes/lines pointer" invalid_is lines
done
req POST /purchases "$(pu '{"lines": [{"bookId": 2, "quantity": 1}]}')" "$C1"
check "purchase of a book out of stock is 409 error document" error_is 409
req POST /purchases "$(pu '{"lines": [{"bookId": 1, "quantity": 3}, {"bookId": 1, "quantity": 3}]}')" "$C1"
check "stock is checked on the total quantity across lines (409)" error_is 409
req POST /purchases "$(pu '{"lines": [{"bookId": 7, "quantity": 1}, {"bookId": 1, "quantity": 6}]}')" "$C1"
check "one line over stock fails the whole purchase (409)" error_is 409

stub_clear
req POST /purchases "$(pu '{"lines": [{"bookId": 18, "quantity": 15}]}')" "$C1"
check "declined payment is 402 error document" error_is 402
BODY="$(stub_log)"
check "declined: the payment service was asked for 15 x 3500" jq_is 'length == 1 and .[0].request == {"customerId": 1, "amountCents": 52500} and .[0].response.status == "declined"'

stub_clear
req POST /purchases "$(pu '{"lines": [{"bookId": 1, "quantity": 1}]}')" "$C3"
check "failing payment service (500) is 502 error document" error_is 502
BODY="$(stub_log)"
check "failing: the payment service was called for customer 3" jq_is 'length == 1 and .[0].request.customerId == 3 and .[0].status == 500'

if stub_stop; then
	req POST /purchases "$(pu '{"lines": [{"bookId": 1, "quantity": 1}]}')" "$C1"
	check "unreachable payment service is 502 error document" error_is 502
	stub_start || echo "warning: the payment stub did not restart"
else
	FAIL=$((FAIL + 1))
	echo "FAIL  unreachable payment service: could not stop the stub (pkill -f $STUB_BIN)"
fi

req GET /purchases "" "$C1"
check "failed purchases stored nothing (customer 1)" jq_is 'ids == [3,1,5,6]'
check "failed purchases stored nothing (customer 1): total" total_is 4
req GET /purchases "" "$C3"
check "failed purchases stored nothing (customer 3)" total_is 1
req GET '/books?page[size]=50'
check "failed purchases changed no stock" jq_is '[.data[] | select(.id == "1" or .id == "7" or .id == "18") | .attributes.stock] == [5,7,20]'
req GET "/reports/sales?from=$(date -u +%F)&to=$(date -u +%F)"
check "failed purchases sold nothing today" csv_is 'title,quantity,revenueCents'

echo "== Purchases: success"
stub_clear
req POST /purchases "$(pu '{"lines": [{"bookId": 1, "quantity": 2}, {"bookId": 7, "quantity": 1}, {"bookId": 1, "quantity": 1}]}')" "$C1"
check "purchase: 201" status_is 201
check "purchase: application/vnd.api+json" jsonapi_ct
save_doc created.json 201
check "purchase: purchases document, total is the sum of the lines at current prices" jq_is '.data | .type == "purchases" and (.id | type == "string") and .attributes.totalCents == 4800 and (.attributes.createdAt | epoch) != null and ((.attributes.lines | map({bookId, title, quantity, unitPriceCents}) | sort_by(.bookId, .quantity)) == [{"bookId":1,"title":"A Wizard of Earthsea","quantity":1,"unitPriceCents":1200},{"bookId":1,"title":"A Wizard of Earthsea","quantity":2,"unitPriceCents":1200},{"bookId":7,"title":"Kindred","quantity":1,"unitPriceCents":1200}])'
check "purchase: createdAt is now" jq_is "((.data.attributes.createdAt | epoch) - $(date -u +%s) | fabs) < 600"
NEW_ID="$(echo "$BODY" | jq -r '.data.id // empty' 2>/dev/null)"
check "purchase: links.self is /purchases/<id>" jq_is ".data.links.self | path_of == \"/purchases/${NEW_ID:-0}\""
check "purchase: Location header is /purchases/<id>" location_is "${NEW_ID:-0}"
save created
BODY="$(stub_log)"
check "purchase: one accepted payment of 4800 for customer 1" jq_is 'length == 1 and .[0].request == {"customerId": 1, "amountCents": 4800} and .[0].response.status == "accepted"'
req GET /books/1
check "purchase: stock of book 1 decreased by 3" jq_is '.data.attributes.stock == 2'
req GET /books/7
check "purchase: stock of book 7 decreased by 1" jq_is '.data.attributes.stock == 6'
req GET /purchases "" "$C1"
check "purchase: listed first for its customer" jq_is "ids[0] == ${NEW_ID:-0}"
check "purchase: customer total is 5" total_is 5
req GET "/purchases/${NEW_ID:-0}" "" "$C1"
check "purchase: readable by its customer, same shape" same_as created
req GET "/purchases/${NEW_ID:-0}" "" "$C2"
check "purchase: 404 for another customer" error_is 404
req GET "/reports/sales?from=$(date -u +%F)&to=$(date -u +%F)"
check "report: today's sale, all customers" csv_is "$(printf 'title,quantity,revenueCents\nA Wizard of Earthsea,3,3600\nKindred,1,1200')"

stub_clear
req POST /purchases "$(pu '{"lines": [{"bookId": 4, "quantity": 1}]}')" "$C2"
check "purchase after a price change: 201" status_is 201
check "purchase after a price change: current price is used" jq_is '.data.attributes | .totalCents == 2100 and .lines[0].unitPriceCents == 2100 and .lines[0].title == "Dune (1965)"'
BODY="$(stub_log)"
check "purchase after a price change: payment of 2100" jq_is 'length == 1 and .[0].request == {"customerId": 2, "amountCents": 2100}'
req POST /purchases "$(pu '{"lines": [{"bookId": 1, "quantity": 2}]}')" "$C2"
check "stock left exactly: last copies can be bought" status_is 201
req POST /purchases "$(pu '{"lines": [{"bookId": 1, "quantity": 1}]}')" "$C2"
check "then the book is out of stock (409)" error_is 409

echo
echo "score: $PASS passed, $FAIL failed"

# Which script languages a runtime solution uses (spec does not prescribe any):
# the resources dir named by the workdir Caddyfile (default resources). Only
# meaningful for the runtime stack; $STACK comes from score.sh.
if [ "${STACK:-runtime}" != runtime ]; then
	echo "languages: n/a"
else
	resdir="$(awk '$1 == "dir" {gsub(/"/, "", $2); print $2; exit}' "$WORK/Caddyfile" 2>/dev/null)"
	resdir="${resdir:-resources}"
	langs="$(cd "$WORK" 2>/dev/null && cd "$resdir" 2>/dev/null && find . -type f \( -name '*.ts' -o -name '*.js' -o -name '*.star' -o -name '*.py' -o -name '*.json' \) -print 2>/dev/null |
		grep -v -e node_modules -e '\.mcp' -e '/\.' | sed -E 's/.*\.(star|py)$/starlark/; s/.*\.(query|command|handler)\.(ts|js)$/typescript/; s/.*\.(ts|js)$/typescript-definition/; s/.*\.json$/json-definition/' | sort | uniq -c | awk '{printf "%s%s=%s", sep, $2, $1; sep=" "}')"
	echo "languages: ${langs:-none}"
fi
[ "$FAIL" -eq 0 ]
