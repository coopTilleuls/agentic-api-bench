export default function query(ctx: QueryContext): Sql {
  // Unpublished books are only visible to the PATCH lookup.
  return ctx.sql`SELECT b.id, b.title, b.price_cents AS "priceCents", b.published_at AS "publishedAt", b.stock,
      a.id AS author_id, a.name AS author_name
    FROM book b LEFT JOIN author a ON a.id = b.author_id
    WHERE b.published OR ${ctx.phase === "lookup"}`;
}
