export function create(input, ctx) {
  const customer = ctx.get("customer");
  const prices = {};
  const stocks = {};
  const wanted = {};
  for (let i = 0; i < input.lines.length; i++) {
    const line = input.lines[i];
    const [book] = ctx.db.query(ctx.sql`SELECT price_cents, stock FROM book WHERE id = ${line.bookId} AND published FOR UPDATE`);
    if (!book) {
      ctx.reject({ message: `book ${line.bookId} is not available`, pointer: `/lines/${i}/bookId`, code: "book_unavailable", status: 422 });
    }
    prices[line.bookId] = book.price_cents;
    stocks[line.bookId] = book.stock;
    wanted[line.bookId] = (wanted[line.bookId] || 0) + line.quantity;
  }
  for (const key of Object.keys(wanted)) {
    if (wanted[key] > stocks[key]) {
      ctx.reject({ message: `not enough stock for book ${key}`, pointer: "/lines", code: "out_of_stock", status: 409 });
    }
  }
  let total = 0;
  for (const line of input.lines) {
    total += line.quantity * prices[line.bookId];
  }
  const [purchase] = ctx.db.query(ctx.sql`INSERT INTO purchase (customer_id, total_cents) VALUES (${customer}, ${total}) RETURNING id`);
  for (const line of input.lines) {
    ctx.db.exec(ctx.sql`INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (${purchase.id}, ${line.bookId}, ${line.quantity}, ${prices[line.bookId]})`);
  }
  for (const key of Object.keys(wanted)) {
    ctx.db.exec(ctx.sql`UPDATE book SET stock = stock - ${wanted[key]} WHERE id = ${Number(key)}`);
  }
  // Last, so that a declined or failed payment rolls everything back.
  const res = ctx.fetch("http://127.0.0.1:18090/payments", { method: "POST", body: { customerId: customer, amountCents: total } });
  if (!res.ok) {
    ctx.reject({ message: "payment service failed", code: "payment_failed", status: 502 });
  }
  let payment = null;
  try {
    payment = res.json();
  } catch (e) {
    payment = null;
  }
  const status = payment ? payment.status : null;
  if (status === "declined") {
    ctx.reject({ message: "payment declined", code: "payment_declined", status: 402 });
  }
  if (status !== "accepted") {
    ctx.reject({ message: "payment service failed", code: "payment_failed", status: 502 });
  }
  return purchase.id;
}
