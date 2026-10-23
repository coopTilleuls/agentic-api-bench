export default function hasPublishedBooks(value: string, ctx) {
  const published = ctx.property("publishedBooks");
  return value === "true" ? ctx.sql`${published} > 0` : ctx.sql`${published} = 0`;
}
