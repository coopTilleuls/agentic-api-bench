import { pool } from '../../lib/db';
import { requireCustomer } from '../../lib/auth';
import { charge, loadPurchases, purchaseJson } from '../../lib/purchases';
import {
  handle,
  HttpError,
  INT_MAX,
  isPlainObject,
  list,
  parsePaging,
  readJson,
} from '../../lib/http';

export const dynamic = 'force-dynamic';

export function GET(request: Request) {
  return handle(async () => {
    const customerId = await requireCustomer(request);
    const { page, itemsPerPage } = parsePaging(new URL(request.url).searchParams);
    const { rows } = await pool.query(
      `select id, created_at, total_cents, refunded_at from purchase
       where customer_id = $1::int
       order by created_at desc, id desc
       limit $2::int offset $3::int`,
      [customerId, itemsPerPage, (page - 1) * itemsPerPage],
    );
    const { rows: count } = await pool.query<{ n: number }>(
      'select count(*)::int as n from purchase where customer_id = $1::int',
      [customerId],
    );
    return list(await loadPurchases(rows), count[0].n, page, itemsPerPage);
  });
}

type RequestedLine = { bookId: number; quantity: number };

function parseLines(body: unknown): RequestedLine[] {
  if (!isPlainObject(body) || !Array.isArray(body.lines) || body.lines.length === 0) {
    throw new HttpError(422, 'lines must be a non-empty list');
  }
  return body.lines.map((line: unknown) => {
    if (!isPlainObject(line)) throw new HttpError(422, 'Each line must be an object');
    const { bookId, quantity } = line;
    if (typeof bookId !== 'number' || !Number.isInteger(bookId) || bookId < 1 || bookId > INT_MAX) {
      throw new HttpError(422, 'bookId must be an integer');
    }
    if (typeof quantity !== 'number' || !Number.isInteger(quantity) || quantity < 1 || quantity > INT_MAX) {
      throw new HttpError(422, 'quantity must be an integer of at least 1');
    }
    return { bookId, quantity };
  });
}

export function POST(request: Request) {
  return handle(async () => {
    const customerId = await requireCustomer(request);
    const lines = parseLines(await readJson(request));

    const wanted = new Map<number, number>();
    for (const line of lines) {
      wanted.set(line.bookId, (wanted.get(line.bookId) ?? 0) + line.quantity);
    }
    const bookIds = [...wanted.keys()].sort((a, b) => a - b);

    const client = await pool.connect();
    try {
      await client.query('begin');

      // Lock the books in id order so concurrent purchases cannot oversell.
      const { rows: books } = await client.query<{
        id: number;
        title: string;
        price_cents: number;
        stock: number;
        published: boolean;
      }>(
        `select id, title, price_cents, stock, published from book
         where id = any($1::int[]) order by id for update`,
        [bookIds],
      );
      const byId = new Map(books.map((book) => [book.id, book]));
      for (const id of bookIds) {
        if (!byId.get(id)?.published) {
          throw new HttpError(422, `Book ${id} is not available`);
        }
      }
      for (const id of bookIds) {
        if (byId.get(id)!.stock < wanted.get(id)!) {
          throw new HttpError(409, `Not enough stock for book ${id}`);
        }
      }

      const priced = lines.map((line) => {
        const book = byId.get(line.bookId)!;
        return {
          bookId: line.bookId,
          quantity: line.quantity,
          title: book.title,
          unitPriceCents: book.price_cents,
        };
      });
      const totalCents = priced.reduce((sum, line) => sum + line.unitPriceCents * line.quantity, 0);

      await charge(customerId, totalCents);

      const { rows: created } = await client.query<{ id: number; created_at: Date }>(
        'insert into purchase (customer_id, total_cents) values ($1::int, $2::int) returning id, created_at',
        [customerId, totalCents],
      );
      const purchase = { ...created[0], total_cents: totalCents, refunded_at: null };
      for (const line of priced) {
        await client.query(
          `insert into purchase_line (purchase_id, book_id, quantity, unit_price_cents)
           values ($1::int, $2::int, $3::int, $4::int)`,
          [purchase.id, line.bookId, line.quantity, line.unitPriceCents],
        );
      }
      for (const [id, quantity] of wanted) {
        await client.query('update book set stock = stock - $2::int where id = $1::int', [id, quantity]);
      }
      await client.query('commit');

      return Response.json(
        purchaseJson(
          purchase,
          priced.map((line) => ({
            purchase_id: purchase.id,
            book_id: line.bookId,
            title: line.title,
            quantity: line.quantity,
            unit_price_cents: line.unitPriceCents,
          })),
        ),
        { status: 201 },
      );
    } catch (error) {
      await client.query('rollback').catch(() => undefined);
      throw error;
    } finally {
      client.release();
    }
  });
}
