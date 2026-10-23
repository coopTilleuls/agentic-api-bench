export default function query(ctx: QueryContext): Sql {
  return ctx.sql`SELECT b.title AS title, sum(pl.quantity)::bigint AS quantity,
    sum(pl.quantity * pl.unit_price_cents)::bigint AS "revenueCents"
    FROM purchase p
    JOIN purchase_line pl ON pl.purchase_id = p.id
    JOIN book b ON b.id = pl.book_id
    WHERE (p.created_at AT TIME ZONE 'UTC')::date BETWEEN ${ctx.get("from")}::date AND ${ctx.get("to")}::date
    GROUP BY b.id, b.title
    ORDER BY "revenueCents" DESC, b.title ASC, b.id ASC`;
}
