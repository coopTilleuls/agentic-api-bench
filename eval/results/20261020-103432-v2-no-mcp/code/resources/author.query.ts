export default function query(ctx: QueryContext): Sql {
  const author = ctx.resource("Author");
  const books = author.relation("books");
  const published = ctx.sql`FROM ${books.table()} WHERE ${author.join("books")} AND ${books.column("published")}`;
  return ctx.sql`SELECT ${author.columns(["id", "name"])},
    (SELECT count(*)::int ${published}) AS "publishedBooks",
    (SELECT coalesce(json_agg(${books.json(["id", "title"])} ORDER BY ${books.column("id")}), '[]') ${published}) AS books
    FROM ${author.table()}`;
}
