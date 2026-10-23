export default function query(ctx: QueryContext): Sql {
  return ctx.sql`
    SELECT p.id, p.created_at AS "createdAt", p.total_cents AS "totalCents",
      (SELECT coalesce(json_agg(json_build_object('bookId', l.book_id, 'title', b.title, 'quantity', l.quantity, 'unitPriceCents', l.unit_price_cents) ORDER BY l.id), '[]')
       FROM purchase_line l JOIN book b ON b.id = l.book_id
       WHERE l.purchase_id = p.id) AS lines
    FROM purchase p
    WHERE p.customer_id = ${ctx.get("customer")}`;
}
