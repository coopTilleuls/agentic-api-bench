const PAYMENTS = "http://127.0.0.1:18090/payments";

export function create(input, ctx) {
  const customer = ctx.get("customer");
  const wanted = new Map<number, number>();
  for (const l of input.lines) wanted.set(l.bookId, (wanted.get(l.bookId) ?? 0) + l.quantity);

  const books = ctx.db.query(ctx.sql`SELECT id, price_cents, stock FROM book WHERE id = ANY(${[...wanted.keys()]}) AND published FOR UPDATE`);
  const byId = new Map(books.map((b) => [b.id, b]));
  input.lines.forEach((l, i) => {
    if (!byId.has(l.bookId)) ctx.reject({ message: `book ${l.bookId} does not exist or is not published`, pointer: `/lines/${i}/bookId` });
  });
  for (const [id, qty] of wanted) {
    const { stock } = byId.get(id);
    if (stock < qty) ctx.reject({ status: 409, code: "out_of_stock", message: `book ${id} has ${stock} copies left, ${qty} asked` });
  }
  const total = input.lines.reduce((sum, l) => sum + l.quantity * byId.get(l.bookId).price_cents, 0);

  const res = ctx.fetch(PAYMENTS, { method: "POST", body: { customerId: customer, amountCents: total } });
  const status = res.ok ? res.json().status : null;
  if (status === "declined") ctx.reject({ status: 402, code: "payment_declined", message: "the payment was declined" });
  if (status !== "accepted") ctx.reject({ status: 502, code: "payment_failed", message: `the payment service failed (HTTP ${res.status})` });

  const [purchase] = ctx.db.query(ctx.sql`INSERT INTO purchase (customer_id, total_cents) VALUES (${customer}, ${total}) RETURNING id`);
  for (const l of input.lines) {
    ctx.db.exec(ctx.sql`INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (${purchase.id}, ${l.bookId}, ${l.quantity}, ${byId.get(l.bookId).price_cents})`);
    ctx.db.exec(ctx.sql`UPDATE book SET stock = stock - ${l.quantity} WHERE id = ${l.bookId}`);
  }
  return purchase.id;
}
