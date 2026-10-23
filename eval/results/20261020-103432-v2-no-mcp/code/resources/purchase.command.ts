export function create(input, ctx) {
  const customer = ctx.get("customer");
  const books = {};
  const needed = {};
  const ids = [];
  input.lines.forEach((line, i) => {
    const [book] = ctx.db.query(ctx.sql`SELECT id, price_cents, stock, published FROM book WHERE id = ${line.bookId} FOR UPDATE`);
    if (!book || !book.published) {
      ctx.reject({ message: `book ${line.bookId} is not available`, pointer: `/lines/${i}/bookId`, status: 422 });
    }
    if (!(line.bookId in books)) ids.push(line.bookId);
    books[line.bookId] = book;
    needed[line.bookId] = (needed[line.bookId] ?? 0) + line.quantity;
  });
  for (const id of ids) {
    if (books[id].stock < needed[id]) {
      ctx.reject({ message: `not enough stock for book ${id}`, status: 409, code: "insufficient_stock" });
    }
  }
  let total = 0;
  for (const line of input.lines) total += line.quantity * books[line.bookId].price_cents;

  let verdict = null;
  try {
    const res = ctx.fetch("http://127.0.0.1:18090/payments", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: { customerId: customer, amountCents: total },
    });
    if (res.ok) verdict = res.json().status;
  } catch (e) {
    verdict = null;
  }
  if (verdict === "declined") ctx.reject({ message: "payment declined", status: 402, code: "payment_declined" });
  if (verdict !== "accepted") ctx.reject({ message: "payment service failed", status: 502, code: "payment_failed" });

  const [purchase] = ctx.db.query(ctx.sql`INSERT INTO purchase (customer_id, total_cents) VALUES (${customer}, ${total}) RETURNING id`);
  for (const line of input.lines) {
    ctx.db.exec(ctx.sql`INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (${purchase.id}, ${line.bookId}, ${line.quantity}, ${books[line.bookId].price_cents})`);
  }
  for (const id of ids) {
    ctx.db.exec(ctx.sql`UPDATE book SET stock = stock - ${needed[id]} WHERE id = ${id}`);
  }
  return purchase.id;
}
