export default function query(ctx: QueryContext): Sql {
  return ctx.sql`SELECT b.title AS title, sum(l.quantity)::int AS quantity,
    sum(l.quantity * l.unit_price_cents)::bigint AS "revenueCents"
    FROM purchase_line l
    JOIN purchase p ON p.id = l.purchase_id
    JOIN book b ON b.id = l.book_id
    WHERE (p.created_at AT TIME ZONE 'UTC') >= ${ctx.get("from")}::date
      AND (p.created_at AT TIME ZONE 'UTC') < (${ctx.get("to")}::date + 1)
    GROUP BY b.id, b.title
    ORDER BY "revenueCents" DESC, b.title ASC, b.id ASC`;
}
