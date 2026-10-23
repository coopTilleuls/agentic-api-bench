export default function query(ctx: QueryContext): Sql {
  return ctx.sql`
    SELECT b.id, b.title, a.name AS author, b.published
    FROM book b
    JOIN author a ON a.id = b.author_id
    WHERE b.published`;
}
