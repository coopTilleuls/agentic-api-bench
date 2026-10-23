export default function query(ctx: QueryContext): Sql {
  return ctx.sql`SELECT a.id AS id, a.name AS name,
    (SELECT count(*)::int FROM book b WHERE b.author_id = a.id AND b.published) AS "publishedBooks",
    (SELECT coalesce(json_agg(json_build_object('id', b.id, 'title', b.title) ORDER BY b.id), '[]'::json)
       FROM book b WHERE b.author_id = a.id AND b.published) AS books
    FROM author a`;
}
