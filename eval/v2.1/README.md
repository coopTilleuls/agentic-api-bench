# Eval v2.1: evolution task

A fresh agent receives the code another agent produced in a v2 run and applies `change-request.md`. It measures what a change costs on each stack: tokens, turns, time, and the lines changed against the code it was given. Run it with

```
eval/run.sh --task evolve --from eval/results/<v2 run dir> [--model M] [--max-turns N] [--smoke]
```

Stack and, for the runtime, the MCP variant come from the source run (`stack`, `init.json`). The workdir is a copy of the source run's `code/` directory (the archive every run makes of its workdir, without `caddy`, `node_modules`, `vendor`, `var`, `.next`, `public/bundles`, `.git`; the directory named by the source's `workdir` file is used only when `code/` is absent). The source is never modified; the runtime's `./caddy` is the current clean eval binary, and for nextjs and apiplatform `npm ci` / `npm install` or `composer install` runs in the stack image through the proxy before the agent starts (`install.log` in the run dir). The source `code/` is also the baseline of `metrics.sh`. The prompt is `change-request.md`, a `---` line, the v2 spec exactly as the source agent saw it (cut from the source `prompt.txt` before the stack footer: `eval/v2/spec.md` has changed since the pilot, `X-Customer-Id` became `Customer-Id`), then the footer of a v2 run. The results dir is `<timestamp>-v2.1-<stack>[-<variant>]` with `task` (`evolve`) and `source_run`.

## Files

- `change-request.md`: the task, given to the agent. Not to be edited once runs exist.
- `migration.sql`: `ALTER TABLE purchase ADD COLUMN refunded_at timestamptz;`. `reset_db` (eval/lib.sh) loads `eval/v2/seed.sql` and then this file. No refunded purchase is seeded: the assertions refund seeded purchases themselves, which also checks that `refundedAt` is `null` everywhere else.
- `assert.sh <base-url> <workdir>`: stack-agnostic, same conventions as `eval/v2/assert.sh`. Prints `regression: x/y`, `change: x/y` and the total `score: N passed, M failed`.
- `eval/score.sh --docker --stack <stack> --spec v2.1 <workdir>` scores an evolved solution.

## Assertions

`regression` is the v2 suite adapted to the change: header `Customer-Id` (already the v2 header), books 20 per page (the order, filter and pagination checks that relied on 10 per page pass `itemsPerPage=10`), `pages` not asserted there. `change` covers each item of the request:

| Item | Assertions |
| --- | --- |
| 1 Header | `X-Customer-Id` alone is 401 on every purchase route including refunds; with both headers only `Customer-Id` counts |
| 2 `pages` | books, authors, purchases; rounding up, exact division, past the last page, filtered, 0 for an empty list |
| 3 Page size | books 20 by default, `itemsPerPage` still 1..50, authors and purchases 10 |
| 4 `q` | title, author name, case, partial, NULL author, `%`/`_` literal, unpublished never found, combined with every filter, sorting and pagination |
| 5 `hasPublishedBooks` | `true`, `false`, 400 for `maybe` and `abc`; with pagination |
| 6 Refunds | 401 before anything; 404 for another customer's (even when already refunded), unknown, malformed; 200 with `refundedAt` (UTC, now) and the purchase shape; stock restored exactly (two lines on one book, a book at 0, the other books untouched); `refundedAt` null everywhere else, in lists, items and the creation answer; 409 twice with stock and `refundedAt` unchanged; payment stub log empty, also for the customer whose payments fail |
| 7 Report | refunded purchases excluded (today, January, a day with only a refunded purchase, February) |

The change block's read assertions run after the v2 reads (seed state), the refund block runs last, comparing stocks relative to what the earlier writes left.

## Ambiguities of the change request (assertions made, judge them)

- `hasPublishedBooks=1`/`0`/`yes`/empty: not asserted. "Any other value answers 400" leaves `1`/`0` ambiguous (API Platform's boolean filter accepts them, and so does the runtime's boolean parameter type); only `true`, `false` and clearly invalid values (`maybe`, `abc`) are asserted.
- Refund of another customer's purchase that is already refunded: 404 (ownership first), asserted.
- `?q=` empty or blank: unspecified, not asserted.
- `pages` when `itemsPerPage` is absurd, `page=0`: as in v2, not asserted.
- A refunded purchase stays in `GET /purchases` and `GET /purchases/{id}` (only the report excludes it): implied by "everything not mentioned keeps working", asserted.
- `refundedAt` on `POST /purchases`: null in the creation answer ("every purchase response"), asserted.
- Refund request body: none sent; refund of a purchase containing an unpublished book is not exercised (no such seeded purchase).
- Whether the refund is a `POST` with an empty body and no `Content-Type`: the assertions send exactly that.

## Reference solution

`example-v2.1/` is `example-v2/` with the change applied: `envelope { pages pages }` in the Caddyfile and the refund declared natively as `POST /purchases/{id}/refund` (resource `Refund`, operation `input` `{}`, command `create` reading `ctx.id`).
