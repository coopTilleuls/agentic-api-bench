function assertPublishable(ctx, authorId: number) {
  const [author] = ctx.db.query(ctx.sql`SELECT name FROM author WHERE id = ${authorId}`);
  if (!author) {
    ctx.reject({ message: `author ${authorId} does not exist`, pointer: "/author_id", hint: "Use the id of an existing author (GET /authors)." });
  }
  return author;
}

export function create(input, ctx) {
  const author = assertPublishable(ctx, input.author_id);
  if (input.published && !author.name) {
    ctx.reject({
      code: "author_without_name",
      message: `author ${input.author_id} has no name, so the book cannot be published`,
      pointer: "/published",
      hint: "Give the author a name first (PATCH /authors/{id}), or create the book unpublished.",
    });
  }
  const [row] = ctx.db.query(ctx.sql`
    INSERT INTO book (title, author_id, published)
    VALUES (${input.title}, ${input.author_id}, ${input.published ?? false})
    RETURNING id`);
  return row.id;
}

export function update(input, ctx) {
  const [current] = ctx.db.query(ctx.sql`SELECT title, author_id, published FROM book WHERE id = ${ctx.id}`);
  const next = { ...current, ...input };
  const author = assertPublishable(ctx, next.author_id);
  if (next.published && !author.name) {
    ctx.reject({ code: "author_without_name", message: `author ${next.author_id} has no name, so the book cannot be published`, pointer: "/published" });
  }
  ctx.db.exec(ctx.sql`
    UPDATE book SET title = ${next.title}, author_id = ${next.author_id}, published = ${next.published}
    WHERE id = ${ctx.id}`);
}

export function remove(input, ctx) {
  ctx.db.exec(ctx.sql`DELETE FROM book WHERE id = ${ctx.id}`);
}
