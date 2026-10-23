#!/usr/bin/env bash
# Fixed HTTP assertions for eval/spec.md, against a running server.
#
#   eval/assert.sh <base-url> <workdir>
#
# Needs curl and jq. <workdir> is only inspected to report which script
# languages the solution uses ($STACK=runtime, else n/a).
set -uo pipefail
BASE="${1:?usage: assert.sh <base-url> <workdir>}"
WORK="${2:?usage: assert.sh <base-url> <workdir>}"
TMP="$(mktemp -d)"
PASS=0
FAIL=0

# req METHOD PATH [BODY] [ACCEPT] -> sets STATUS, BODY, CTYPE. JSON-LD is
# asked explicitly: a solution restricting its formats passes as long as it
# still serves the responses the spec describes.
req() {
	local method="$1" path="$2" data="${3:-}" accept="${4:-application/ld+json}"
	local args=(-s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X "$method" --max-time 10 -H "Accept: $accept")
	if [ -n "$data" ]; then args+=(-H 'Content-Type: application/json' --data "$data"); fi
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
		printf 'FAIL  %s  (status %s, body %.200s)\n' "$name" "$STATUS" "$(echo "$BODY" | tr '\n' ' ')"
	fi
}

status_is() { [ "$STATUS" = "$1" ]; }
jq_is() { echo "$BODY" | jq -e "$1" >/dev/null; }

# Reads.
req GET /books
check "books: list is 200" status_is 200
check "books: only published books are counted (5)" jq_is '.totalItems == 5'
check "books: 2 per page" jq_is '.member | length == 2'
check "books: ordered by id, with the author name" jq_is '.member[0].title == "A Wizard of Earthsea" and .member[0].author == "Ursula K. Le Guin" and .member[0].id == 1'

req GET '/books?page=3'
check "books: page 3 holds the 5th published book" jq_is '(.member | length == 1) and .member[0].title == "Kindred"'

req GET '/books?author=Frank%20Herbert'
check "books: filter by author name" jq_is '.totalItems == 2 and ([.member[].title] == ["Dune", "Dune Messiah"])'

req GET /books/4
check "books: item lookup" jq_is '.id == 4 and .title == "Dune" and .author == "Frank Herbert"'

req GET /books/3
check "books: unpublished book is 404" status_is 404

req GET /books/999
check "books: unknown book is 404" status_is 404

req GET /authors
check "authors: list counts all authors (4)" jq_is '.totalItems == 4 and (.member | length == 2)'

req GET /authors/2
check "authors: item lookup" jq_is '.id == 2 and .name == "Frank Herbert"'

req GET /quotes
check "quotes: list from the external service" jq_is '.totalItems == 5 and (.member | length == 2) and .member[0].id == 1'
check "quotes: fields mapped (text, author)" jq_is '(.member[0].text | startswith("It is good")) and .member[0].author == "Ursula K. Le Guin"'

req GET '/quotes?author=Frank%20Herbert'
check "quotes: filter by author" jq_is '.totalItems == 2 and ([.member[].id] == [2, 5])'

req GET '/quotes?page=3'
check "quotes: pagination" jq_is '(.member | length == 1) and .member[0].id == 5'

req GET /quotes/3
check "quotes: item lookup" jq_is '.id == 3 and .author == "Octavia E. Butler"'

req GET /quotes/42
check "quotes: unknown quote is 404" status_is 404

req GET /business/export "" "text/csv, */*;q=0.1"
check "export: 200" status_is 200
check "export: served as CSV" bash -c '[[ "$0" == text/csv* ]]' "$CTYPE"
check "export: exact CSV content" test "$BODY" = "$(printf 'author,published_books\nFrank Herbert,2\nOctavia E. Butler,1\nUrsula K. Le Guin,2')"

# Writes.
req POST /authors '{"name": "N. K. Jemisin"}'
check "authors: create is 201" status_is 201
NEW_AUTHOR="$(echo "$BODY" | jq -r '.id // empty' 2>/dev/null)"
check "authors: create returns the author" jq_is '.name == "N. K. Jemisin" and (.id | type == "number")'

req POST /books "{\"title\": \"The Fifth Season\", \"author_id\": ${NEW_AUTHOR:-0}, \"published\": true}"
check "books: create is 201" status_is 201
check "books: create returns the book with its author name" jq_is '.title == "The Fifth Season" and .author == "N. K. Jemisin"'

req GET /books
check "books: created book is listed" jq_is '.totalItems == 6'

req POST /books '{"title": "Ghost Story", "author_id": 4, "published": true}'
check "books: publishing for an author without name is 422" status_is 422

req POST /books '{"author_id": 1, "published": true}'
check "books: missing title is rejected (400/422)" bash -c '[ "$0" = 400 ] || [ "$0" = 422 ]' "$STATUS"

req GET /books
check "books: rejected creates left nothing behind" jq_is '.totalItems == 6'

req PATCH /books/4 '{"title": "Dune (1965)"}'
check "books: patch is 200" status_is 200
check "books: patch returns the updated book" jq_is '.title == "Dune (1965)" and .author == "Frank Herbert"'

req DELETE /books/5
check "books: delete is 204" status_is 204
req GET /books/5
check "books: deleted book is 404" status_is 404

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
