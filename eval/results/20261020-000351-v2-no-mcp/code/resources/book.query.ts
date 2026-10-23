export default function query(ctx: QueryContext): Sql {
  // PATCH looks unpublished books up too; reads and the read-back after a write only see published ones
  const visible = ctx.phase === "lookup" ? ctx.sql`TRUE` : ctx.sql`b.published`;
  return ctx.sql`SELECT b.id AS id, b.title AS title, b.price_cents AS "priceCents",
    b.published_at AS "publishedAt", b.stock AS stock,
    a.id AS author_id, a.name AS author_name
    FROM book b JOIN author a ON a.id = b.author_id
    WHERE ${visible}`;
}
