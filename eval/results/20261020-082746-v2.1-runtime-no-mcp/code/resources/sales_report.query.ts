export default function query(ctx: QueryContext): Sql {
  const from = ctx.get("from");
  const to = ctx.get("to");
  if (from !== null && to !== null && from > to) {
    ctx.reject({ message: "from must not be after to", hint: "Use from <= to, both YYYY-MM-DD.", code: "invalid_parameter", status: 400 });
  }
  return ctx.sql`SELECT b.title, sum(l.quantity)::int AS quantity, sum(l.quantity * l.unit_price_cents)::bigint AS "revenueCents"
    FROM purchase_line l
    JOIN purchase p ON p.id = l.purchase_id
    JOIN book b ON b.id = l.book_id
    WHERE p.refunded_at IS NULL
      AND (p.created_at AT TIME ZONE 'UTC') >= ${from}::date
      AND (p.created_at AT TIME ZONE 'UTC') < ${to}::date + 1
    GROUP BY b.id, b.title
    ORDER BY "revenueCents" DESC, b.title ASC, b.id ASC`;
}
