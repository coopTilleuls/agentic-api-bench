export function create(input, ctx) {
  const [purchase] = ctx.db.query(ctx.sql`SELECT refunded_at FROM purchase WHERE id = ${ctx.id} FOR UPDATE`);
  if (purchase.refunded_at !== null) ctx.reject({ status: 409, code: "already_refunded", message: `purchase ${ctx.id} is already refunded` });
  ctx.db.exec(ctx.sql`
    UPDATE book SET stock = stock + bought.quantity
    FROM (SELECT book_id, sum(quantity) AS quantity FROM purchase_line WHERE purchase_id = ${ctx.id} GROUP BY book_id) bought
    WHERE book.id = bought.book_id`);
  ctx.db.exec(ctx.sql`UPDATE purchase SET refunded_at = now() WHERE id = ${ctx.id}`);
}
