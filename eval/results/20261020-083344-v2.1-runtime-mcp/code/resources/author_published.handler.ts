export default function hasPublishedBooks(value: string, ctx) {
  const count = ctx.property("publishedBooks");
  return value === "true" ? ctx.sql`${count} > 0` : ctx.sql`${count} = 0`;
}
