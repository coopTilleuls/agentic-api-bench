export function create(input, ctx) {
  const customer = ctx.get("customer");
  const books = {};
  const bookIds = [];
  for (let i = 0; i < input.lines.length; i++) {
    const line = input.lines[i];
    if (books[line.bookId] === undefined) {
      const [book] = ctx.db.query(ctx.sql`SELECT id, price_cents AS "priceCents", stock FROM book
        WHERE id = ${line.bookId} AND published FOR UPDATE`);
      if (!book) ctx.reject({ message: `book ${line.bookId} is not available`, pointer: `/lines/${i}/bookId`, status: 422 });
      books[line.bookId] = { priceCents: book.priceCents, stock: book.stock, wanted: 0 };
      bookIds.push(line.bookId);
    }
    books[line.bookId].wanted += line.quantity;
  }
  for (const bookId of bookIds) {
    if (books[bookId].wanted > books[bookId].stock) {
      ctx.reject({ message: `not enough stock for book ${bookId}`, code: "insufficient_stock", status: 409 });
    }
  }
  let totalCents = 0;
  for (const line of input.lines) totalCents += line.quantity * books[line.bookId].priceCents;

  const res = ctx.fetch("http://127.0.0.1:18090/payments", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: { customerId: customer, amountCents: totalCents },
  });
  let answer = null;
  try {
    answer = res.json();
  } catch (e) {
    answer = null;
  }
  const status = answer && answer.status;
  if (res.ok && status === "declined") ctx.reject({ message: "payment declined", code: "payment_declined", status: 402 });
  if (!res.ok || status !== "accepted") ctx.reject({ message: "payment service failed", code: "payment_failed", status: 502 });

  const [purchase] = ctx.db.query(ctx.sql`INSERT INTO purchase (customer_id, created_at, total_cents)
    VALUES (${customer}, now(), ${totalCents}) RETURNING id`);
  for (const line of input.lines) {
    ctx.db.exec(ctx.sql`INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents)
      VALUES (${purchase.id}, ${line.bookId}, ${line.quantity}, ${books[line.bookId].priceCents})`);
  }
  for (const bookId of bookIds) {
    ctx.db.exec(ctx.sql`UPDATE book SET stock = stock - ${books[bookId].wanted} WHERE id = ${bookId}`);
  }
  return purchase.id;
}
