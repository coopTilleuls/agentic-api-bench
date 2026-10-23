// Customer-Id: an unknown customer is rejected with the parameter's status (401).
export default function customer(id: number, ctx) {
  const [row] = ctx.db.query(ctx.sql`SELECT id FROM customer WHERE id = ${id}::bigint`);
  if (!row) ctx.reject({ message: `unknown customer ${id}` });
  ctx.set("customer", row.id);
  return null;
}
