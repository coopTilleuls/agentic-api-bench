export function create(input, ctx) {
  const [purchase] = ctx.db.query(ctx.sql`SELECT refunded_at FROM purchase WHERE id = ${ctx.id} FOR UPDATE`);
  if (purchase.refunded_at) {
    ctx.reject({ message: `purchase ${ctx.id} is already refunded`, status: 409, code: "already_refunded" });
  }
  const lines = ctx.db.query(ctx.sql`SELECT book_id, quantity FROM purchase_line WHERE purchase_id = ${ctx.id}`);
  for (const line of lines) {
    ctx.db.exec(ctx.sql`UPDATE book SET stock = stock + ${line.quantity} WHERE id = ${line.book_id}`);
  }
  ctx.db.exec(ctx.sql`UPDATE purchase SET refunded_at = now() WHERE id = ${ctx.id}`);
}
