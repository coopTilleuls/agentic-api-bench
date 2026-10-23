export default function customer(id, ctx) {
  const [row] = ctx.db.query(ctx.sql`SELECT id FROM customer WHERE id = ${id}::bigint`);
  if (!row) ctx.reject({ message: `unknown customer ${id}`, status: 401 });
  ctx.set("customer", row.id);
  return null;
}
