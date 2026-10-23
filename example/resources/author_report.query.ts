export default function query(ctx: QueryContext): Sql {
  return ctx.sql`
    SELECT a.name AS author, count(b.id)::int AS published_books
    FROM author a
    LEFT JOIN book b ON b.author_id = a.id AND b.published
    WHERE a.name IS NOT NULL
    GROUP BY a.name
    ORDER BY a.name`;
}
