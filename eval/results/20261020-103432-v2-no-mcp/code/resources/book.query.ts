export default function query(ctx: QueryContext): Sql {
  const book = ctx.resource("Book");
  const author = book.relation("author");
  return ctx.sql`SELECT ${book.columns(["id", "title", "priceCents", "publishedAt", "stock"])}, ${author.columns()}
    FROM ${book.table()} LEFT JOIN ${author.table()} ON ${book.join("author")}
    WHERE ${book.column("published")} OR ${ctx.phase === "lookup"}`;
}
