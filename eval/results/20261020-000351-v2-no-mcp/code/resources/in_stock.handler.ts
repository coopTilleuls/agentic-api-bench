export default function inStock(value: boolean, ctx) {
  const stock = ctx.property("stock");
  return value ? ctx.sql`${stock} > 0` : ctx.sql`${stock} = 0`;
}
