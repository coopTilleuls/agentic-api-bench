export default function query(ctx: QueryContext): Sql {
  const from = ctx.get("from");
  const to = ctx.get("to");
  if (from > to) ctx.reject({ message: "from must not be after to", status: 400, code: "invalid_parameter" });
  return ctx.sql`SELECT b.title AS title, sum(pl.quantity)::int AS quantity, sum(pl.quantity * pl.unit_price_cents)::int AS "revenueCents"
    FROM purchase_line pl
    JOIN purchase p ON p.id = pl.purchase_id
    JOIN book b ON b.id = pl.book_id
    WHERE (p.created_at AT TIME ZONE 'UTC')::date BETWEEN ${from}::date AND ${to}::date
    GROUP BY b.id, b.title
    ORDER BY "revenueCents" DESC, b.title ASC, b.id ASC`;
}
