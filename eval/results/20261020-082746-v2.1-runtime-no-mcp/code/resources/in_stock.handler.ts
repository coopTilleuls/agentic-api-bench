export default function inStock(value: string, ctx) {
  const stock = ctx.property("stock");
  return value === "true" ? ctx.sql`${stock} > 0` : ctx.sql`${stock} = 0`;
}
