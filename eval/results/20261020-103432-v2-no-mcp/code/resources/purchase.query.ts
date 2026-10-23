export default function query(ctx: QueryContext): Sql {
  return ctx.sql`SELECT p.id, p.created_at AS "createdAt", p.total_cents AS "totalCents",
    (SELECT coalesce(json_agg(json_build_object('bookId', pl.book_id, 'title', b.title, 'quantity', pl.quantity, 'unitPriceCents', pl.unit_price_cents) ORDER BY pl.id), '[]')
      FROM purchase_line pl JOIN book b ON b.id = pl.book_id WHERE pl.purchase_id = p.id) AS lines
    FROM purchase p WHERE p.customer_id = ${ctx.get("customer")}`;
}
