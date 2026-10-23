export function create(input, ctx) {
  const customer = ctx.get("customer");

  // total quantity asked per book, and the books in first-seen order
  const wanted = {};
  const bookIds = [];
  for (const line of input.lines) {
    if (wanted[line.bookId] === undefined) {
      wanted[line.bookId] = 0;
      bookIds.push(line.bookId);
    }
    wanted[line.bookId] += line.quantity;
  }

  // lock each book so the stock check and the decrement cannot race with another purchase
  const books = {};
  for (const id of bookIds) {
    const [book] = ctx.db.query(ctx.sql`SELECT id, price_cents, stock, published FROM book WHERE id = ${id} FOR UPDATE`);
    if (!book || !book.published) {
      ctx.reject({ message: `book ${id} is not available`, pointer: "/lines", status: 422 });
    }
    if (book.stock < wanted[id]) {
      ctx.reject({ message: `not enough stock for book ${id}: ${book.stock} left, ${wanted[id]} asked`, pointer: "/lines", status: 409 });
    }
    books[id] = book;
  }

  let total = 0;
  for (const line of input.lines) {
    total += books[line.bookId].price_cents * line.quantity;
  }

  const res = ctx.fetch("http://127.0.0.1:18090/payments", {
    method: "POST",
    body: { customerId: customer, amountCents: total },
  });
  let answer = null;
  if (res.ok) {
    try {
      answer = res.json();
    } catch (e) {
      answer = null;
    }
  }
  if (answer && answer.status === "declined") {
    ctx.reject({ message: "payment declined", status: 402, code: "payment_declined" });
  }
  if (!answer || answer.status !== "accepted") {
    ctx.reject({ message: "payment service failed", status: 502, code: "payment_failed" });
  }

  const [purchase] = ctx.db.query(ctx.sql`INSERT INTO purchase (customer_id, total_cents) VALUES (${customer}, ${total}) RETURNING id`);
  for (const line of input.lines) {
    ctx.db.exec(ctx.sql`INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents)
      VALUES (${purchase.id}, ${line.bookId}, ${line.quantity}, ${books[line.bookId].price_cents})`);
  }
  for (const id of bookIds) {
    ctx.db.exec(ctx.sql`UPDATE book SET stock = stock - ${wanted[id]} WHERE id = ${id}`);
  }
  return purchase.id;
}
