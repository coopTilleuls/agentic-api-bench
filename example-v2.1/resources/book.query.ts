// Unpublished books are hidden from reads; a PATCH still finds them (phase "lookup").
export default function query(ctx: QueryContext): Sql {
  const book = ctx.resource("Book");
  const author = book.relation("author");
  return ctx.sql`
    SELECT ${book.columns()}, ${author.columns()}
    FROM ${book.table()} JOIN ${author.table()} ON ${book.join("author")}
    WHERE ${book.column("published")} OR ${ctx.phase === "lookup"}`;
}
