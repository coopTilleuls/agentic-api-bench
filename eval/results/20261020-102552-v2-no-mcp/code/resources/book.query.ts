export default function query(ctx: QueryContext): Sql {
  return ctx.sql`SELECT b.id, b.title, b.price_cents AS "priceCents", b.published_at AS "publishedAt", b.stock,
    b.author_id AS author_id, a.name AS author_name
    FROM book b JOIN author a ON a.id = b.author_id
    WHERE b.published OR ${ctx.phase === "lookup"}`;
}
