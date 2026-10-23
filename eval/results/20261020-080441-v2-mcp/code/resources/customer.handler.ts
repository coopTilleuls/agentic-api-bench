export default function customer(id: number, ctx) {
  const [row] = ctx.db.query(ctx.sql`SELECT id FROM customer WHERE id = ${id}`);
  if (!row) ctx.reject({ message: `unknown customer ${id}` });
  ctx.set("customer", row.id);
  return null;
}
