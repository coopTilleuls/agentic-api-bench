import { Injectable } from '@nestjs/common';
import type { PoolClient } from 'pg';
import { pool } from '../common/db.js';
import { ApiError, apiError, ErrorObject } from '../common/errors.js';
import { isObject, PurchaseLineRow, PurchaseRow } from '../common/jsonapi.js';

export interface PurchaseLineInput {
  bookId: number;
  quantity: number;
}

const PAYMENT_URL = 'http://127.0.0.1:18090/payments';
const PAYMENT_TIMEOUT_MS = 5000;

/** Resolves the Customer-Id header to an existing customer id, or answers 401. */
export async function authenticateCustomer(header: string | undefined): Promise<number> {
  const unauthorized = (): ApiError => apiError(401, 'a valid Customer-Id header is required');
  if (header === undefined || !/^[1-9]\d{0,9}$/.test(header.trim())) throw unauthorized();
  const id = Number(header.trim());
  if (id > 2147483647) throw unauthorized();
  const { rowCount } = await pool.query('SELECT 1 FROM customer WHERE id = $1', [id]);
  if (!rowCount) throw unauthorized();
  return id;
}

async function charge(customerId: number, amountCents: number): Promise<void> {
  let response: Response;
  try {
    response = await fetch(PAYMENT_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ customerId, amountCents }),
      signal: AbortSignal.timeout(PAYMENT_TIMEOUT_MS),
    });
  } catch {
    throw apiError(502, 'payment service unreachable');
  }
  let body: unknown;
  try {
    body = await response.json();
  } catch {
    body = undefined;
  }
  const status = isObject(body) ? body.status : undefined;
  if (response.ok && status === 'accepted') return;
  if (status === 'declined') throw apiError(402, 'payment declined');
  throw apiError(502, 'payment service failed');
}

@Injectable()
export class PurchasesService {
  async list(customerId: number, limit: number, offset: number): Promise<{ rows: PurchaseRow[]; total: number }> {
    const total = await pool.query<{ total: number }>(
      'SELECT count(*) AS total FROM purchase WHERE customer_id = $1',
      [customerId],
    );
    const rows = await pool.query<PurchaseRow>(
      `SELECT id, created_at, total_cents FROM purchase
        WHERE customer_id = $1
        ORDER BY created_at DESC, id DESC
        LIMIT $2 OFFSET $3`,
      [customerId, limit, offset],
    );
    return { rows: rows.rows, total: total.rows[0].total };
  }

  async find(customerId: number, id: number): Promise<PurchaseRow | undefined> {
    const { rows } = await pool.query<PurchaseRow>(
      'SELECT id, created_at, total_cents FROM purchase WHERE id = $1 AND customer_id = $2',
      [id, customerId],
    );
    return rows[0];
  }

  async lines(purchaseIds: number[]): Promise<PurchaseLineRow[]> {
    const { rows } = await pool.query<PurchaseLineRow>(
      `SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
         FROM purchase_line pl JOIN book b ON b.id = pl.book_id
        WHERE pl.purchase_id = ANY($1::int[])
        ORDER BY pl.id`,
      [purchaseIds],
    );
    return rows;
  }

  /**
   * Buys the lines in one transaction. Book rows are locked while stock is checked
   * and the payment is taken, so a failure leaves nothing stored and no stock changed.
   */
  async create(customerId: number, lines: PurchaseLineInput[]): Promise<PurchaseRow> {
    const client: PoolClient = await pool.connect();
    try {
      await client.query('BEGIN');

      const bookIds = [...new Set(lines.map((l) => l.bookId))].sort((a, b) => a - b);
      const { rows: books } = await client.query<{
        id: number;
        price_cents: number;
        stock: number;
        published: boolean;
      }>('SELECT id, price_cents, stock, published FROM book WHERE id = ANY($1::int[]) ORDER BY id FOR UPDATE', [
        bookIds,
      ]);
      const byId = new Map(books.map((b) => [b.id, b]));

      const errors: ErrorObject[] = [];
      lines.forEach((line, i) => {
        const book = byId.get(line.bookId);
        if (!book || !book.published) {
          errors.push({
            status: '422',
            title: 'Unprocessable Entity',
            detail: `book ${line.bookId} is not available`,
            source: { pointer: `/data/attributes/lines/${i}/bookId` },
          });
        }
      });
      if (errors.length) throw new ApiError(422, errors);

      const wanted = new Map<number, number>();
      for (const line of lines) {
        wanted.set(line.bookId, (wanted.get(line.bookId) ?? 0) + line.quantity);
      }
      const short = [...wanted].filter(([id, quantity]) => byId.get(id)!.stock < quantity);
      if (short.length) {
        throw apiError(409, `not enough stock for book(s) ${short.map(([id]) => id).join(', ')}`);
      }

      const totalCents = lines.reduce((sum, l) => sum + l.quantity * byId.get(l.bookId)!.price_cents, 0);
      await charge(customerId, totalCents);

      const { rows: stored } = await client.query<PurchaseRow>(
        'INSERT INTO purchase (customer_id, total_cents) VALUES ($1, $2) RETURNING id, created_at, total_cents',
        [customerId, totalCents],
      );
      const purchase = stored[0];
      for (const line of lines) {
        await client.query(
          `INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents)
           VALUES ($1, $2, $3, $4)`,
          [purchase.id, line.bookId, line.quantity, byId.get(line.bookId)!.price_cents],
        );
      }
      for (const [id, quantity] of wanted) {
        await client.query('UPDATE book SET stock = stock - $1 WHERE id = $2', [quantity, id]);
      }

      await client.query('COMMIT');
      return purchase;
    } catch (error) {
      await client.query('ROLLBACK').catch(() => undefined);
      throw error;
    } finally {
      client.release();
    }
  }
}
