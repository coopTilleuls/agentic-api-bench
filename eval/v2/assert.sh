#!/usr/bin/env bash
# Fixed HTTP assertions for eval/v2/spec.md, against a running server, for
# every stack (this runtime, Next.js, API Platform).
#
#   eval/v2/assert.sh <base-url> <workdir>
#
# Needs curl and jq. The database must be freshly reset from eval/v2/seed.sql
# (score.sh does it): reads run first on the seed, then writes whose effects
# later assertions account for. The payment stub (eval/docker/stub) is
# reached at $STUB_URL, its call log through GET/DELETE /_stub/payments; it is
# stopped and restarted with $STUB_BIN to test an unreachable service.
# <workdir> is only inspected to report which script languages a runtime
# solution uses.
set -uo pipefail
BASE="${1:?usage: assert.sh <base-url> <workdir>}"
WORK="${2:?usage: assert.sh <base-url> <workdir>}"
STUB_URL="${STUB_URL:-http://127.0.0.1:18090}"
STUB_BIN="${STUB_BIN:-/opt/stub/stub}"
TMP="$(mktemp -d)"
PASS=0
FAIL=0

# req METHOD PATH [BODY] [HEADER...] -> sets STATUS, BODY, CTYPE. Query
# strings are sent as written (curl globbing off for order[...]).
req() {
	local method="$1" path="$2" data="${3:-}"
	shift 3 2>/dev/null || shift $#
	local args=(-g -s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X "$method" --max-time 15 -H 'Accept: application/json')
	if [ -n "$data" ]; then args+=(-H 'Content-Type: application/json' --data "$data"); fi
	local h
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

# jq_is FILTER: evaluated on BODY with helpers: ids (item ids of a list),
# epoch (ISO 8601 UTC timestamp to seconds, null if not UTC ISO 8601).
JQ_DEFS='
def ids: [.items[].id];
def epoch: if type == "string" then (capture("^(?<d>[0-9]{4}-[0-9]{2}-[0-9]{2})T(?<t>[0-9]{2}:[0-9]{2}:[0-9]{2})(\\.[0-9]+)?(Z|[+-]00:?00)$") // null) | if . == null then null else ("\(.d)T\(.t)Z" | fromdateiso8601) end else null end;
def is_error: (.error | type == "string") and (.error | length > 0);
'
jq_is() { echo "$BODY" | jq -e "$JQ_DEFS $1" >/dev/null; }

# error_is STATUS: the status and the {"error": "<message>"} shape.
error_is() { status_is "$1" && jq_is 'is_error'; }

ids_are() { status_is 200 && jq_is "ids == $1"; }

# csv_is EXPECTED: the body as CSV, ignoring CR and the final newline.
csv_is() { [ "$(printf '%s' "$BODY" | tr -d '\r' | sed -e '$a\')" = "$(printf '%s\n' "$1")" ]; }

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

C1='Customer-Id: 1'
C2='Customer-Id: 2'
C3='Customer-Id: 3'
C4='Customer-Id: 4'

echo "== Books: list, pagination"
req GET /books
check "books: list is 200" status_is 200
check "books: response is JSON" bash -c '[[ "$0" == application/json* ]]' "$CTYPE"
check "books: total counts published books only (14)" jq_is '.total == 14'
check "books: page 1, 10 per page by default" jq_is '.page == 1 and .itemsPerPage == 10 and (.items | length) == 10'
check "books: id order by default, unpublished absent" jq_is 'ids == [1,2,4,5,6,7,10,11,12,13]'
check "books: item shape (id, title, priceCents, publishedAt, stock, author {id, name})" jq_is '.items[0] | .id == 1 and .title == "A Wizard of Earthsea" and .priceCents == 1200 and .publishedAt == "1968-11-01" and .stock == 5 and .author.id == 1 and .author.name == "Ursula K. Le Guin"'
check "books: anonymous author name is null" jq_is '(.items[] | select(.id == 10) | .author) as $a | $a.id == 4 and $a.name == null'

req GET '/books?page=2'
check "books: page 2" jq_is 'ids == [14,16,17,18] and .page == 2 and .total == 14'
req GET '/books?page=3'
check "books: page past the last is 200" status_is 200
check "books: page past the last has empty items" jq_is '.items == [] and .total == 14 and .page == 3'
req GET '/books?itemsPerPage=5&page=3'
check "books: itemsPerPage=5 page 3" jq_is 'ids == [14,16,17,18] and .itemsPerPage == 5 and .page == 3 and .total == 14'
req GET '/books?itemsPerPage=1&page=14'
check "books: itemsPerPage=1 last page" jq_is 'ids == [18]'
req GET '/books?itemsPerPage=50'
check "books: itemsPerPage=50 is allowed" jq_is '(.items | length) == 14 and .itemsPerPage == 50'
for bad in 0 51 abc -1 2.5; do
	req GET "/books?itemsPerPage=$bad"
	check "books: itemsPerPage=$bad is 400 {error}" error_is 400
done
req GET '/books?page=abc'
check "books: page=abc is 400 {error}" error_is 400
req GET '/books?foo=bar&sort=title'
check "books: unknown query parameters are ignored" jq_is '.total == 14 and ids == [1,2,4,5,6,7,10,11,12,13]'

echo "== Books: filters"
req GET '/books?title=dune'
check "filter title: contains, case-insensitive" ids_are '[4,5,6,11,18]'
req GET '/books?title=DUNE&itemsPerPage=50'
check "filter title: upper case query" ids_are '[4,5,6,11,18]'
req GET '/books?title=lilith%27s'
check "filter title: apostrophe" ids_are '[13]'
req GET '/books?title=%25'
check "filter title: % is a literal character" jq_is '.total == 0 and .items == []'
req GET '/books?title=_'
check "filter title: _ is a literal character" jq_is '.total == 0 and .items == []'
req GET '/books?author=Frank%20Herbert'
check "filter author: exact name" ids_are '[4,5,6,11,18]'
req GET '/books?author=Frank'
check "filter author: not a substring match" jq_is '.total == 0'
req GET '/books?priceCents[gte]=1000&itemsPerPage=50'
check "filter priceCents[gte]: 1000 included, 999 excluded" jq_is '(ids | index(5) != null) and (ids | index(14) != null) and (ids | index(12) == null) and (ids | index(10) == null) and .total == 12'
req GET '/books?priceCents[lte]=2000&itemsPerPage=50'
check "filter priceCents[lte]: 2000 included, 2001 excluded" jq_is '(ids | index(4) != null) and (ids | index(13) == null) and .total == 11'
req GET '/books?priceCents[gte]=1000&priceCents[lte]=2000'
check "filter priceCents range" ids_are '[1,2,4,5,6,7,14,16,17]'
req GET '/books?publishedAt[after]=2020-01-01&itemsPerPage=50'
check "filter publishedAt[after]: bound included" ids_are '[10,11,12,13,17,18]'
req GET '/books?publishedAt[before]=2020-12-31&itemsPerPage=50'
check "filter publishedAt[before]: bound included" jq_is '(ids | index(11) != null) and (ids | index(17) == null) and (ids | index(18) == null) and .total == 12'
req GET '/books?publishedAt[after]=2020-01-01&publishedAt[before]=2020-12-31'
check "filter publishedAt range" ids_are '[10,11,12,13]'
req GET '/books?inStock=true&itemsPerPage=50'
check "filter inStock=true: stock > 0" jq_is '.total == 11 and ([.items[].stock] | all(. > 0)) and (ids | index(2) == null)'
req GET '/books?inStock=false'
check "filter inStock=false: stock 0" ids_are '[2,6,12]'
req GET '/books?title=dune&inStock=true&priceCents[lte]=2500&order[priceCents]=desc'
check "filters combined with sorting" ids_are '[11,4,5]'
req GET '/books?title=dune&order[priceCents]=asc&itemsPerPage=2&page=2'
check "filters combined with pagination" jq_is 'ids == [4,11] and .total == 5 and .page == 2 and .itemsPerPage == 2'
for bad in 'priceCents[gte]=abc' 'priceCents[lte]=' 'publishedAt[after]=2020-13-01' 'publishedAt[before]=yesterday' 'publishedAt[after]=2020-02-30' 'inStock=yes'; do
	req GET "/books?$bad"
	check "filter $bad is 400 {error}" error_is 400
done

echo "== Books: sorting"
req GET '/books?order[priceCents]=asc'
check "order priceCents asc, ties by id" ids_are '[10,12,5,14,1,7,16,2,6,17]'
req GET '/books?order[priceCents]=asc&page=2'
check "order priceCents asc, page 2" ids_are '[4,13,11,18]'
req GET '/books?order[priceCents]=desc'
check "order priceCents desc, ties by id ascending" ids_are '[18,11,13,4,17,2,6,16,1,7]'
req GET '/books?order[title]=asc'
check "order title asc, ties by id" ids_are '[1,12,6,4,18,5,16,7,13,17]'
req GET '/books?order[title]=desc&page=2'
check "order title desc, page 2" ids_are '[18,6,12,1]'
req GET '/books?order[publishedAt]=asc'
check "order publishedAt asc" ids_are '[4,1,2,5,14,6,7,16,10,12]'
req GET '/books?order[publishedAt]=desc&itemsPerPage=4'
check "order publishedAt desc, ties by id" ids_are '[17,18,11,12]'
req GET '/books?order[priceCents]=asc&order[title]=desc'
check "order: several keys in the order given" ids_are '[10,12,14,5,7,1,16,2,6,17]'
req GET '/books?order[title]=asc&order[priceCents]=desc'
check "order: keys reversed give another order" ids_are '[1,12,6,18,4,5,16,7,13,17]'
for bad in 'order[stock]=asc' 'order[title]=up' 'order[priceCents]=' 'order[id]=sideways'; do
	req GET "/books?$bad"
	check "$bad is 400 {error}" error_is 400
done

echo "== Books: items"
req GET /books/4
check "book 4: 200" status_is 200
check "book 4: shape" jq_is '.id == 4 and .title == "Dune" and .priceCents == 2000 and .publishedAt == "1965-08-01" and .stock == 10 and .author == {"id": 2, "name": "Frank Herbert"}'
save book4
req GET /books/10
check "book 10: anonymous author" jq_is '.author.id == 4 and .author.name == null'
req GET /books/3
check "unpublished book is 404 {error}" error_is 404
req GET /books/999
check "unknown book is 404 {error}" error_is 404
req GET /books/abc
check "malformed book id is 404" error_is 404

echo "== Authors"
req GET /authors
check "authors: list" jq_is 'ids == [1,2,3,4,5] and .total == 5 and .page == 1 and .itemsPerPage == 10'
check "authors: publishedBooks counts published books only" jq_is '[.items[].publishedBooks] == [4,5,4,1,0]'
check "authors: anonymous author has a null name" jq_is '.items[3].name == null and .items[0].name == "Ursula K. Le Guin"'
req GET '/authors?itemsPerPage=2&page=3'
check "authors: paginated" jq_is 'ids == [5] and .total == 5'
req GET /authors/1
check "author 1: published books in id order" jq_is '.id == 1 and .name == "Ursula K. Le Guin" and .books == [{"id":1,"title":"A Wizard of Earthsea"},{"id":2,"title":"The Left Hand of Darkness"},{"id":14,"title":"The Lathe of Heaven"},{"id":17,"title":"Tehanu"}]'
req GET /authors/4
check "author 4: anonymous, unpublished book hidden" jq_is '.name == null and .books == [{"id":10,"title":"The Anonymous Chronicle"}]'
req GET /authors/5
check "author 5: no published book" jq_is '.name == "Isaac Asimov" and .books == []'
req GET /authors/99
check "unknown author is 404 {error}" error_is 404
req GET /authors/abc
check "malformed author id is 404" error_is 404

echo "== Purchases: identification"
for h in '' 'Customer-Id: abc' 'Customer-Id: 99' 'Customer-Id: 1.5'; do
	label="${h:-no header}"
	req GET /purchases "" ${h:+"$h"}
	check "GET /purchases with $label is 401 {error}" error_is 401
	req GET /purchases/1 "" ${h:+"$h"}
	check "GET /purchases/1 with $label is 401" error_is 401
	req GET /purchases/abc "" ${h:+"$h"}
	check "GET /purchases/abc with $label is 401 (checked first)" error_is 401
	req POST /purchases '{"lines": []}' ${h:+"$h"}
	check "POST /purchases (invalid body) with $label is 401 (checked first)" error_is 401
done

echo "== Purchases: reads"
req GET /purchases "" "$C1"
check "customer 1: own purchases, most recent first" ids_are '[3,1,5,6]'
check "customer 1: total, page, itemsPerPage" jq_is '.total == 4 and .page == 1 and .itemsPerPage == 10'
check "purchase shape: createdAt (UTC ISO 8601), totalCents, lines" jq_is '.items[0] | (.createdAt | epoch) == ("2026-02-01T00:00:00Z" | fromdateiso8601) and .totalCents == 3000 and .lines == [{"bookId":5,"title":"Dune Messiah","quantity":3,"unitPriceCents":1000}]'
check "purchase timestamps parse" jq_is '[.items[].createdAt | epoch] == (["2026-02-01T00:00:00Z","2026-01-05T10:00:00Z","2026-01-01T00:00:00Z","2025-12-31T23:59:59Z"] | map(fromdateiso8601))'
req GET '/purchases?itemsPerPage=2&page=2' "" "$C1"
check "customer 1: paginated" jq_is 'ids == [5,6] and .total == 4'
req GET /purchases "" "$C4"
check "customer without purchases: empty list" jq_is '.items == [] and .total == 0'
req GET /purchases/1 "" "$C1"
check "purchase 1: lines with stored unit prices" jq_is '.id == 1 and .totalCents == 5200 and (.lines | sort_by(.bookId)) == [{"bookId":4,"title":"Dune","quantity":2,"unitPriceCents":2000},{"bookId":7,"title":"Kindred","quantity":1,"unitPriceCents":1200}]'
req GET /purchases/5 "" "$C1"
check "purchase 5: unit price as stored, not current" jq_is '.lines == [{"bookId":1,"title":"A Wizard of Earthsea","quantity":1,"unitPriceCents":1100}]'
req GET /purchases/2 "" "$C1"
check "another customer's purchase is 404 {error}" error_is 404
req GET /purchases/2 "" "$C2"
check "the owner sees it" jq_is '.id == 2 and .totalCents == 3200'
req GET /purchases/999 "" "$C1"
check "unknown purchase is 404" error_is 404
req GET /purchases/abc "" "$C1"
check "malformed purchase id is 404" error_is 404

echo "== Sales report"
req GET '/reports/sales?from=2026-01-01&to=2026-01-31'
check "report January: 200" status_is 200
check "report: served as CSV" bash -c '[[ "$0" == text/csv* ]]' "$CTYPE"
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
	check "report ?$bad is 400 {error}" error_is 400
done

echo "== Books: PATCH"
req PATCH /books/4 '{"title": "Dune (1965)"}'
check "patch title: 200" status_is 200
check "patch title: book in the list shape, only title changed" jq_is '.id == 4 and .title == "Dune (1965)" and .priceCents == 2000 and .stock == 10 and .publishedAt == "1965-08-01" and .author == {"id": 2, "name": "Frank Herbert"}'
req PATCH /books/4 '{"priceCents": 2100, "isbn": "978-0441013593", "author": {"id": 1}}'
check "patch: unknown fields ignored" jq_is '.priceCents == 2100 and .title == "Dune (1965)" and .author.id == 2'
req PATCH /books/4 '{"stock": 0}'
check "patch: stock 0 allowed" jq_is '.stock == 0'
req PATCH /books/4 '{"stock": 10, "priceCents": 0}'
check "patch: several fields, price 0 allowed" jq_is '.stock == 10 and .priceCents == 0'
req PATCH /books/4 '{"priceCents": 2100}'
req GET /books/4
save book4
for bad in '{"priceCents": -1}' '{"stock": -1}' '{"stock": 1.5}' '{"priceCents": "2000"}' '{"stock": null}' '{"title": null}' '{"title": ""}' '{"title": "Ok", "stock": -5}' '{"title": 42}'; do
	req PATCH /books/4 "$bad"
	check "patch $bad is 422 {error}" error_is 422
done
req GET /books/4
check "rejected patches changed nothing" same_as book4
req PATCH /books/3 '{"stock": 7, "title": "The Dispossessed"}'
check "patch on an unpublished book is 204" status_is 204
req GET /books/3
check "unpublished book stays hidden" error_is 404
req PATCH /books/999 '{"stock": 1}'
check "patch unknown book is 404" error_is 404
req PATCH /books/abc '{"stock": 1}'
check "patch malformed id is 404" error_is 404

echo "== Purchases: failures change nothing"
stub_clear
req GET /purchases "" "$C1"
check "customer 1 has 4 purchases before" jq_is '.total == 4'
for bad in '{}' '{"lines": []}' '{"lines": "x"}' '{"lines": [{"bookId": 3, "quantity": 1}]}' '{"lines": [{"bookId": 999, "quantity": 1}]}' '{"lines": [{"bookId": 1, "quantity": 0}]}' '{"lines": [{"bookId": 1, "quantity": 1.5}]}' '{"lines": [{"bookId": 1, "quantity": "2"}]}' '{"lines": [{"bookId": 1}]}' '{"lines": [{"quantity": 1}]}' '{"lines": [{"bookId": 1, "quantity": 1}, {"bookId": 8, "quantity": 1}]}'; do
	req POST /purchases "$bad" "$C1"
	check "purchase $bad is 422 {error}" error_is 422
done
req POST /purchases '{"lines": [{"bookId": 2, "quantity": 1}]}' "$C1"
check "purchase of a book out of stock is 409 {error}" error_is 409
req POST /purchases '{"lines": [{"bookId": 1, "quantity": 3}, {"bookId": 1, "quantity": 3}]}' "$C1"
check "stock is checked on the total quantity across lines (409)" error_is 409
req POST /purchases '{"lines": [{"bookId": 7, "quantity": 1}, {"bookId": 1, "quantity": 6}]}' "$C1"
check "one line over stock fails the whole purchase (409)" error_is 409

stub_clear
req POST /purchases '{"lines": [{"bookId": 18, "quantity": 15}]}' "$C1"
check "declined payment is 402 {error}" error_is 402
BODY="$(stub_log)"
check "declined: the payment service was asked for 15 x 3500" jq_is 'length == 1 and .[0].request == {"customerId": 1, "amountCents": 52500} and .[0].response.status == "declined"'

stub_clear
req POST /purchases '{"lines": [{"bookId": 1, "quantity": 1}]}' "$C3"
check "failing payment service (500) is 502 {error}" error_is 502
BODY="$(stub_log)"
check "failing: the payment service was called for customer 3" jq_is 'length == 1 and .[0].request.customerId == 3 and .[0].status == 500'

if stub_stop; then
	req POST /purchases '{"lines": [{"bookId": 1, "quantity": 1}]}' "$C1"
	check "unreachable payment service is 502 {error}" error_is 502
	stub_start || echo "warning: the payment stub did not restart"
else
	FAIL=$((FAIL + 1))
	echo "FAIL  unreachable payment service: could not stop the stub (pkill -f $STUB_BIN)"
fi

req GET /purchases "" "$C1"
check "failed purchases stored nothing (customer 1)" jq_is '.total == 4 and ids == [3,1,5,6]'
req GET /purchases "" "$C3"
check "failed purchases stored nothing (customer 3)" jq_is '.total == 1'
req GET '/books?itemsPerPage=50'
check "failed purchases changed no stock" jq_is '[.items[] | select(.id == 1 or .id == 7 or .id == 18) | .stock] == [5,7,20]'
req GET "/reports/sales?from=$(date -u +%F)&to=$(date -u +%F)"
check "failed purchases sold nothing today" csv_is 'title,quantity,revenueCents'

echo "== Purchases: success"
stub_clear
req POST /purchases '{"lines": [{"bookId": 1, "quantity": 2}, {"bookId": 7, "quantity": 1}, {"bookId": 1, "quantity": 1}]}' "$C1"
check "purchase: 201" status_is 201
check "purchase: shape, total is the sum of the lines at current prices" jq_is '(.id | type) == "number" and .totalCents == 4800 and (.createdAt | epoch) != null and ((.lines | map({bookId, title, quantity, unitPriceCents}) | sort_by(.bookId, .quantity)) == [{"bookId":1,"title":"A Wizard of Earthsea","quantity":1,"unitPriceCents":1200},{"bookId":1,"title":"A Wizard of Earthsea","quantity":2,"unitPriceCents":1200},{"bookId":7,"title":"Kindred","quantity":1,"unitPriceCents":1200}])'
check "purchase: createdAt is now" jq_is "((.createdAt | epoch) - $(date -u +%s) | fabs) < 600"
NEW_ID="$(echo "$BODY" | jq -r '.id // empty' 2>/dev/null)"
save created
BODY="$(stub_log)"
check "purchase: one accepted payment of 4800 for customer 1" jq_is 'length == 1 and .[0].request == {"customerId": 1, "amountCents": 4800} and .[0].response.status == "accepted"'
req GET /books/1
check "purchase: stock of book 1 decreased by 3" jq_is '.stock == 2'
req GET /books/7
check "purchase: stock of book 7 decreased by 1" jq_is '.stock == 6'
req GET /purchases "" "$C1"
check "purchase: listed first for its customer" jq_is ".total == 5 and ids[0] == ${NEW_ID:-0}"
req GET "/purchases/${NEW_ID:-0}" "" "$C1"
check "purchase: readable by its customer, same shape" same_as created
req GET "/purchases/${NEW_ID:-0}" "" "$C2"
check "purchase: 404 for another customer" error_is 404
req GET "/reports/sales?from=$(date -u +%F)&to=$(date -u +%F)"
check "report: today's sale, all customers" csv_is "$(printf 'title,quantity,revenueCents\nA Wizard of Earthsea,3,3600\nKindred,1,1200')"

stub_clear
req POST /purchases '{"lines": [{"bookId": 4, "quantity": 1}]}' "$C2"
check "purchase after a price change: 201" status_is 201
check "purchase after a price change: current price is used" jq_is '.totalCents == 2100 and .lines[0].unitPriceCents == 2100 and .lines[0].title == "Dune (1965)"'
BODY="$(stub_log)"
check "purchase after a price change: payment of 2100" jq_is 'length == 1 and .[0].request == {"customerId": 2, "amountCents": 2100}'
req POST /purchases '{"lines": [{"bookId": 1, "quantity": 2}]}' "$C2"
check "stock left exactly: last copies can be bought" status_is 201
req POST /purchases '{"lines": [{"bookId": 1, "quantity": 1}]}' "$C2"
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
