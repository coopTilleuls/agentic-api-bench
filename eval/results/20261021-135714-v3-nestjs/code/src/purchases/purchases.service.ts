import { Inject, Injectable } from '@nestjs/common';
import pg from 'pg';
import { PG_POOL } from '../database/database.module.js';
import { apiError, invalidFields } from '../common/errors.js';
import { Pagination } from '../common/query.js';
import { ResourceObject } from '../common/jsonapi.js';

const MAX_INTEGER = 2_147_483_647;
const PAYMENT_URL = 'http://127.0.0.1:18090/payments';
const PAYMENT_TIMEOUT_MS = 5000;

export const PURCHASE_SORT: Record<string, string> = {
  createdAt: 'p.created_at',
  totalCents: 'p.total_cents',
};

export interface PurchaseLineInput {
  bookId: number;
  quantity: number;
}

export interface PurchaseLine {
  bookId: number;
  title: string;
  quantity: number;
  unitPriceCents: number;
}

interface PurchaseRow {
  id: number;
  created_at: Date;
  total_cents: number;
}

interface LineRow {
  purchase_id: number;
  book_id: number;
  title: string;
  quantity: number;
  unit_price_cents: number;
}

interface BookLockRow {
  id: number;
  title: string;
  price_cents: number;
  stock: number;
  published: boolean;
}

export function purchaseResource(
  id: number,
  createdAt: Date,
  totalCents: number,
  lines: PurchaseLine[],
): ResourceObject {
  return {
    type: 'purchases',
    id: String(id),
    attributes: { createdAt: createdAt.toISOString(), totalCents, lines },
    links: { self: `/purchases/${id}` },
  };
}

/** Asks the payment service to charge the customer; throws 402 when declined and 502 on any other failure. */
async function charge(customerId: number, amountCents: number): Promise<void> {
  const response = await fetch(PAYMENT_URL, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ customerId, amountCents }),
    signal: AbortSignal.timeout(PAYMENT_TIMEOUT_MS),
  }).catch(() => undefined);
  if (!response) {
    throw apiError(502, 'The payment service is unreachable');
  }

  const body: unknown = await response.json().catch(() => undefined);
  const status =
    typeof body === 'object' && body !== null && 'status' in body
      ? body.status
      : undefined;
  if (response.ok && status === 'accepted') return;
  if (response.ok && status === 'declined') {
    throw apiError(402, 'The payment was declined');
  }
  throw apiError(502, 'The payment service failed');
}

@Injectable()
export class PurchasesService {
  constructor(@Inject(PG_POOL) private readonly pool: pg.Pool) {}

  /** Returns the customer id of the Customer-Id header, or answers 401. */
  async authenticate(header: string | undefined): Promise<number> {
    const unauthorized = () =>
      apiError(401, 'A valid Customer-Id header is required');
    if (
      header === undefined ||
      !/^[1-9]\d*$/.test(header) ||
      Number(header) > MAX_INTEGER
    ) {
      throw unauthorized();
    }
    const customerId = Number(header);
    const result = await this.pool.query(
      'SELECT id FROM customer WHERE id = $1',
      [customerId],
    );
    if (result.rowCount === 0) {
      throw unauthorized();
    }
    return customerId;
  }

  async list(
    customerId: number,
    page: Pagination,
    sort: string[],
  ): Promise<{ purchases: ResourceObject[]; total: number }> {
    const order =
      sort.length > 0
        ? [...sort, 'p.id ASC']
        : ['p.created_at DESC', 'p.id DESC'];
    const count = await this.pool.query<{ count: number }>(
      'SELECT count(*)::int AS count FROM purchase WHERE customer_id = $1',
      [customerId],
    );
    const rows = await this.pool.query<PurchaseRow>(
      `SELECT p.id, p.created_at, p.total_cents
         FROM purchase p
        WHERE p.customer_id = $1
        ORDER BY ${order.join(', ')}
        LIMIT ${page.size} OFFSET ${page.offset}`,
      [customerId],
    );
    const lines = await this.linesOf(rows.rows.map((row) => row.id));
    return {
      purchases: rows.rows.map((row) =>
        purchaseResource(
          row.id,
          row.created_at,
          row.total_cents,
          lines.get(row.id) ?? [],
        ),
      ),
      total: count.rows[0].count,
    };
  }

  async findOne(
    customerId: number,
    id: number,
  ): Promise<ResourceObject | undefined> {
    const result = await this.pool.query<PurchaseRow>(
      'SELECT p.id, p.created_at, p.total_cents FROM purchase p WHERE p.id = $1 AND p.customer_id = $2',
      [id, customerId],
    );
    const row = result.rows[0];
    if (!row) return undefined;
    const lines = await this.linesOf([row.id]);
    return purchaseResource(
      row.id,
      row.created_at,
      row.total_cents,
      lines.get(row.id) ?? [],
    );
  }

  /**
   * Buys the lines in one transaction: the books are locked, the stock and the payment are
   * checked, then the purchase is stored and the stock decreased. Any failure rolls it all back.
   */
  async create(
    customerId: number,
    lines: PurchaseLineInput[],
  ): Promise<ResourceObject> {
    const client = await this.pool.connect();
    try {
      await client.query('BEGIN');

      const bookIds = [...new Set(lines.map((line) => line.bookId))].sort(
        (a, b) => a - b,
      );
      const locked = await client.query<BookLockRow>(
        'SELECT id, title, price_cents, stock, published FROM book WHERE id = ANY($1::int[]) ORDER BY id FOR UPDATE',
        [bookIds],
      );
      const books = new Map(locked.rows.map((book) => [book.id, book]));

      const unavailable = lines.flatMap((line, index) =>
        books.get(line.bookId)?.published
          ? []
          : [
              {
                pointer: `/data/attributes/lines/${index}/bookId`,
                detail: `Book ${line.bookId} is not available`,
              },
            ],
      );
      if (unavailable.length > 0) {
        throw invalidFields(unavailable);
      }

      const wanted = new Map<number, number>();
      for (const line of lines) {
        wanted.set(line.bookId, (wanted.get(line.bookId) ?? 0) + line.quantity);
      }
      for (const [bookId, quantity] of wanted) {
        if (books.get(bookId)!.stock < quantity) {
          throw apiError(409, `Not enough stock for book ${bookId}`);
        }
      }

      const totalCents = lines.reduce(
        (sum, line) =>
          sum + books.get(line.bookId)!.price_cents * line.quantity,
        0,
      );
      await charge(customerId, totalCents);

      const inserted = await client.query<{ id: number; created_at: Date }>(
        'INSERT INTO purchase (customer_id, total_cents) VALUES ($1, $2) RETURNING id, created_at',
        [customerId, totalCents],
      );
      const purchase = inserted.rows[0];
      for (const line of lines) {
        const book = books.get(line.bookId)!;
        await client.query(
          'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES ($1, $2, $3, $4)',
          [purchase.id, book.id, line.quantity, book.price_cents],
        );
      }
      for (const [bookId, quantity] of wanted) {
        await client.query('UPDATE book SET stock = stock - $2 WHERE id = $1', [
          bookId,
          quantity,
        ]);
      }

      await client.query('COMMIT');
      return purchaseResource(
        purchase.id,
        purchase.created_at,
        totalCents,
        lines.map((line) => {
          const book = books.get(line.bookId)!;
          return {
            bookId: book.id,
            title: book.title,
            quantity: line.quantity,
            unitPriceCents: book.price_cents,
          };
        }),
      );
    } catch (error) {
      await client.query('ROLLBACK').catch(() => undefined);
      throw error;
    } finally {
      client.release();
    }
  }

  private async linesOf(
    purchaseIds: number[],
  ): Promise<Map<number, PurchaseLine[]>> {
    const lines = new Map<number, PurchaseLine[]>();
    if (purchaseIds.length === 0) return lines;
    const rows = await this.pool.query<LineRow>(
      `SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
         FROM purchase_line pl
         JOIN book b ON b.id = pl.book_id
        WHERE pl.purchase_id = ANY($1::int[])
        ORDER BY pl.id`,
      [purchaseIds],
    );
    for (const row of rows.rows) {
      const list = lines.get(row.purchase_id) ?? [];
      list.push({
        bookId: row.book_id,
        title: row.title,
        quantity: row.quantity,
        unitPriceCents: row.unit_price_cents,
      });
      lines.set(row.purchase_id, list);
    }
    return lines;
  }
}
