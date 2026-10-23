export default function query(ctx: QueryContext): Sql {
  const from = ctx.get("from");
  const to = ctx.get("to");
  if (from && to && from > to) {
    ctx.reject({ message: "from must not be after to", status: 400, code: "invalid_parameter", pointer: "/from" });
  }
  return ctx.sql`SELECT b.title, SUM(l.quantity) AS quantity, SUM(l.quantity * l.unit_price_cents) AS "revenueCents"
    FROM purchase_line l
    JOIN purchase p ON p.id = l.purchase_id
    JOIN book b ON b.id = l.book_id
    WHERE p.created_at >= (${from}::date::timestamp AT TIME ZONE 'UTC')
      AND p.created_at < ((${to}::date + 1)::timestamp AT TIME ZONE 'UTC')
    GROUP BY b.id, b.title
    ORDER BY "revenueCents" DESC, b.title ASC, b.id ASC`;
}
