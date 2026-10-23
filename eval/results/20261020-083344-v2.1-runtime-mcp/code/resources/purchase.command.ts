export function create(input, ctx) {
  const customer = ctx.get("customer");
  const ids = input.lines.map((l) => l.bookId).filter((id, i, all) => all.indexOf(id) === i);
  const books = ctx.db.query(ctx.sql`SELECT id, price_cents, stock, published FROM book
    WHERE id = ANY(${ids}) ORDER BY id FOR UPDATE`);
  const byId = {};
  for (const b of books) byId[String(b.id)] = b;

  const wanted = {};
  for (let i = 0; i < input.lines.length; i++) {
    const line = input.lines[i];
    const book = byId[String(line.bookId)];
    if (!book || !book.published) {
      ctx.reject({ message: `book ${line.bookId} is not available`, pointer: `/lines/${i}/bookId`,
        hint: "Only published books can be bought." });
    }
    wanted[String(line.bookId)] = (wanted[String(line.bookId)] || 0) + line.quantity;
  }
  for (const id of Object.keys(wanted)) {
    if (byId[id].stock < wanted[id]) {
      ctx.reject({ message: `not enough stock for book ${id}`, status: 409, code: "insufficient_stock" });
    }
  }

  let total = 0;
  for (const line of input.lines) total += byId[String(line.bookId)].price_cents * line.quantity;

  const res = ctx.fetch("http://127.0.0.1:18090/payments", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: { customerId: customer, amountCents: total },
  });
  let answer = null;
  if (res.ok) {
    try { answer = res.json(); } catch (e) { answer = null; }
  }
  const status = answer ? answer.status : null;
  if (status === "declined") ctx.reject({ message: "payment declined", status: 402, code: "payment_declined" });
  if (status !== "accepted") ctx.reject({ message: "payment service failed", status: 502, code: "payment_failed" });

  const [purchase] = ctx.db.query(ctx.sql`INSERT INTO purchase (customer_id, created_at, total_cents)
    VALUES (${customer}, now(), ${total}) RETURNING id`);
  for (const line of input.lines) {
    ctx.db.exec(ctx.sql`INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents)
      VALUES (${purchase.id}, ${line.bookId}, ${line.quantity}, ${byId[String(line.bookId)].price_cents})`);
  }
  for (const id of Object.keys(wanted)) {
    ctx.db.exec(ctx.sql`UPDATE book SET stock = stock - ${wanted[id]} WHERE id = ${Number(id)}`);
  }
  return purchase.id;
}
