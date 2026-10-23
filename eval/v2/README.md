# Eval v2

`spec.md` is the task given to the agent, identical for every stack (this runtime, Next.js, API Platform). It pins a plain JSON contract (`{items, total, page, itemsPerPage}`, `{"error"}`) so the same HTTP assertions score every stack. Nothing below may be copied into `spec.md`: the agent must discover the payment failures through the API behaviour the spec describes.

The customer header is `Customer-Id` (RFC 6648 deprecates the `X-` prefix); the pilot runs of 2026-10-19/20 used `X-Customer-Id`.

## Database

`seed.sql` creates the five tables of the spec and loads deterministic rows (explicit ids, sequences reset after). What it exercises:

| Case | Rows |
| --- | --- |
| Published books, 2 pages at 10 per page | 14 published (ids 1, 2, 4–7, 10–14, 16–18) |
| Unpublished (absent from lists, 404, PATCH still works) | 3, 8, 9, 15 |
| Anonymous author (`name` NULL) | author 4, with one published book (10) and one unpublished (9) |
| Author with no published book (`publishedBooks` 0, empty `books`) | author 5 |
| `?title=dune` (case-insensitive contains) | 4, 5, 6, 11, 18 |
| `?author=Frank%20Herbert` | 4, 5, 6, 11, 18 |
| Price bounds `[gte]=1000`, `[lte]=2000` | 999 (12), 1000 (5, 14), 2000 (4), 2001 (13) |
| Date bounds `[after]=2020-01-01`, `[before]=2020-12-31` | 2019-12-31 (16), 2020-01-01 (10), 2020-12-31 (11), 2021-01-01 (17, 18) |
| `?inStock=true` (stock 0 excluded) | stock 0: 2, 6, 12 |
| Sort ties broken by `id` | price 1000 (5, 14), 1200 (1, 7), 1500 (2, 6); date 2020-06-15 (12, 13), 2021-01-01 (17, 18); title `Dune` (4, 18) |
| Purchases most recent first, not id order | customer 1: ids 3, 1, 5, 6 by `created_at` desc |
| Another customer's purchase is 404 | customer 2 owns 2, 4, 7 |
| Customer without purchases | customer 4 |
| Customer whose payments fail | customer 3 (one seeded purchase, 8) |
| Report bounds, whole UTC days | 2025-12-31T23:59:59Z (out of January), 2026-01-01T00:00Z, 2026-01-31T23:30Z (in), 2026-02-01T00:00Z (out of January) |
| Report uses the stored unit price, not the current price | line 5 (book 1 at 1100, now 1200), line 7 (book 13 at 1200, now 2001) |
| Report groups by book, not title | February has two `Dune` lines (books 18 and 4) |
| Report tie on `revenueCents` broken by `title` | January: `Kindred` and `Lilith's Brood`, both 1200 |

Expected reports on the seed:

```
GET /reports/sales?from=2026-01-01&to=2026-01-31
title,quantity,revenueCents
Dune,3,6000
A Wizard of Earthsea,2,2300
Kindred,1,1200
Lilith's Brood,1,1200

GET /reports/sales?from=2026-02-01&to=2026-02-28
title,quantity,revenueCents
Dune,1,3500
Dune Messiah,3,3000
The Road to Dune,1,2500
Kindred,2,2400
Dune,1,2000
The Anonymous Chronicle,1,800
```

No CHECK constraint guards `stock >= 0` or `price_cents >= 0`: the 422 on negative values and the 409 on insufficient stock must come from the API, on every stack.

## Payment stub

`eval/docker/stub/` (static Go binary, `127.0.0.1:18090`) serves both specs: `GET /quotes.json` for v1, unchanged, and `POST /payments` for v2. Rules, in this order:

| Request | Answer |
| --- | --- |
| Body not JSON, or `customerId` / `amountCents` not integers, or `amountCents` < 0 | `400 {"error": "..."}` |
| `customerId` 3 | `500 {"error": "internal error"}` (the API must answer 502) |
| `amountCents` > 50000 | `200 {"status": "declined"}` (the API must answer 402) |
| otherwise | `200 {"status": "accepted"}` |

Unreachable (the API must answer 502) is tested by stopping the stub.

For the scorer only: `GET /_stub/payments` lists every `/payments` call received (`request`, `status`, `response`), `DELETE /_stub/payments` clears that log. Declined example on the seed: customer 1 buying 15 copies of book 18 (15 × 3500 = 52500, stock 20).

Environment: `STUB_PORT` (default 18090), `STUB_QUOTES` (default `/opt/stub/quotes.json`; `eval/stub/quotes.json` outside the image). On the host: `cd eval/docker/stub && STUB_QUOTES=../../stub/quotes.json go run . &`.
