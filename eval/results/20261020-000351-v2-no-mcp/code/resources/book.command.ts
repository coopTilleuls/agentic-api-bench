export function update(input, ctx) {
  if ("title" in input) ctx.db.exec(ctx.sql`UPDATE book SET title = ${input.title} WHERE id = ${ctx.id}`);
  if ("priceCents" in input) ctx.db.exec(ctx.sql`UPDATE book SET price_cents = ${input.priceCents} WHERE id = ${ctx.id}`);
  if ("stock" in input) ctx.db.exec(ctx.sql`UPDATE book SET stock = ${input.stock} WHERE id = ${ctx.id}`);
}
