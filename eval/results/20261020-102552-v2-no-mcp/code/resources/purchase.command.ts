export function create(input, ctx) {
  const quantities = new Map();
  input.lines.forEach((line) => quantities.set(line.bookId, (quantities.get(line.bookId) ?? 0) + line.quantity));
  const rows = ctx.db.query(ctx.sql`SELECT id, price_cents, stock, published FROM book
    WHERE id = ANY(${Array.from(quantities.keys())}) ORDER BY id FOR UPDATE`);
  const books = new Map(rows.map((b) => [b.id, b]));

  input.lines.forEach((line, i) => {
    const book = books.get(line.bookId);
    if (!book || !book.published) {
      ctx.reject({ message: `book ${line.bookId} is not available`, pointer: `/lines/${i}/bookId`, status: 422, code: "unavailable_book" });
    }
  });
  quantities.forEach((quantity, id) => {
    if (books.get(id).stock < quantity) {
      ctx.reject({ message: `not enough stock for book ${id}`, status: 409, code: "insufficient_stock" });
    }
  });

  const total = input.lines.reduce((sum, line) => sum + line.quantity * books.get(line.bookId).price_cents, 0);
  const res = ctx.fetch("http://127.0.0.1:18090/payments", {
    method: "POST",
    body: { customerId: ctx.get("customer"), amountCents: total },
  });
  if (!res.ok) ctx.reject({ message: "payment service failed", status: 502, code: "payment_failed" });
  let status = null;
  try {
    status = res.json().status;
  } catch (e) {
    status = null;
  }
  if (status === "declined") ctx.reject({ message: "payment declined", status: 402, code: "payment_declined" });
  if (status !== "accepted") ctx.reject({ message: "unexpected payment answer", status: 502, code: "payment_failed" });

  const [purchase] = ctx.db.query(ctx.sql`INSERT INTO purchase (customer_id, total_cents)
    VALUES (${ctx.get("customer")}, ${total}) RETURNING id`);
  input.lines.forEach((line) => {
    ctx.db.exec(ctx.sql`INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents)
      VALUES (${purchase.id}, ${line.bookId}, ${line.quantity}, ${books.get(line.bookId).price_cents})`);
  });
  quantities.forEach((quantity, id) => {
    ctx.db.exec(ctx.sql`UPDATE book SET stock = stock - ${quantity} WHERE id = ${id}`);
  });
  return purchase.id;
}
