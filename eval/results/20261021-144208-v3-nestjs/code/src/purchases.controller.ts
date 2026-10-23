import { Controller, Get, Param, Post, Req, Res } from '@nestjs/common';
import type { Request, Response } from 'express';
import { Db } from './db.js';
import { numericId } from './authors.controller.js';
import {
  ApiError,
  fail,
  fieldsParam,
  includeParam,
  invalid,
  isObject,
  jsonBody,
  listDocument,
  MAX_INT4,
  notFound,
  pageParams,
  Resource,
  requireJsonApi,
  searchParams,
  singleDocument,
  sortParam,
} from './jsonapi.js';

const PURCHASE_SORT = { createdAt: 'p.created_at', totalCents: 'p.total_cents' };
const PAYMENT_URL = 'http://127.0.0.1:18090/payments';

interface PurchaseRow {
  id: number;
  created_at: string;
  total_cents: number;
}

interface LineRow {
  purchase_id: number;
  book_id: number;
  quantity: number;
  unit_price_cents: number;
  title: string;
}

interface BookStockRow {
  id: number;
  title: string;
  price_cents: number;
  stock: number;
}

interface LineInput {
  bookId: number;
  quantity: number;
}

// Checks the Customer-Id header; anything not naming a known customer answers 401.
export async function currentCustomer(db: Db, req: Request): Promise<number> {
  const header = req.headers['customer-id'];
  const unauthorized = () => fail(401, 'a valid Customer-Id header is required');
  if (typeof header !== 'string' || !/^\d{1,10}$/.test(header.trim())) throw unauthorized();
  const id = Number(header.trim());
  if (id < 1 || id > MAX_INT4) throw unauthorized();
  const { rowCount } = await db.query('SELECT 1 FROM customer WHERE id = $1', [id]);
  if (!rowCount) throw unauthorized();
  return id;
}

function purchaseResource(p: PurchaseRow, lines: LineRow[]): Resource {
  return {
    type: 'purchases',
    id: String(p.id),
    attributes: {
      createdAt: p.created_at,
      totalCents: p.total_cents,
      lines: lines.map((l) => ({
        bookId: l.book_id,
        title: l.title,
        quantity: l.quantity,
        unitPriceCents: l.unit_price_cents,
      })),
    },
    links: { self: `/purchases/${p.id}` },
  };
}

async function linesFor(db: Db, purchaseIds: number[]): Promise<Map<number, LineRow[]>> {
  const map = new Map<number, LineRow[]>();
  if (purchaseIds.length === 0) return map;
  const { rows } = await db.query<LineRow>(
    `SELECT pl.purchase_id, pl.book_id, pl.quantity, pl.unit_price_cents, b.title
       FROM purchase_line pl JOIN book b ON b.id = pl.book_id
      WHERE pl.purchase_id = ANY($1::int[])
      ORDER BY pl.id`,
    [purchaseIds],
  );
  for (const r of rows) {
    const list = map.get(r.purchase_id) ?? [];
    list.push(r);
    map.set(r.purchase_id, list);
  }
  return map;
}

function requireJsonBodyObject(req: Request): Record<string, any> {
  requireJsonApi(req);
  const body = jsonBody(req);
  if (!isObject(body)) throw invalid([{ pointer: '/data', detail: 'data must be an object' }]);
  return body;
}

// Validates the shape of the request; book existence and stock are checked against the database.
function parseLines(body: Record<string, any>): LineInput[] {
  const data = body.data;
  if (!isObject(data)) throw invalid([{ pointer: '/data', detail: 'data must be an object' }]);
  if (data.type !== 'purchases') throw fail(409, 'data.type must be purchases', { pointer: '/data/type' });
  const attributes = data.attributes;
  if (!isObject(attributes)) throw invalid([{ pointer: '/data/attributes', detail: 'attributes must be an object' }]);
  const lines = attributes.lines;
  if (!Array.isArray(lines) || lines.length === 0) {
    throw invalid([{ pointer: '/data/attributes/lines', detail: 'lines must be a non-empty array' }]);
  }
  const problems: { pointer: string; detail: string }[] = [];
  const out: LineInput[] = [];
  lines.forEach((line, i) => {
    const base = `/data/attributes/lines/${i}`;
    if (!isObject(line)) {
      problems.push({ pointer: base, detail: 'line must be an object' });
      return;
    }
    const { bookId, quantity } = line;
    if (!Number.isInteger(bookId) || (bookId as number) < 1 || (bookId as number) > MAX_INT4) {
      problems.push({ pointer: `${base}/bookId`, detail: 'bookId must be a positive integer' });
    }
    if (!Number.isInteger(quantity) || (quantity as number) < 1) {
      problems.push({ pointer: `${base}/quantity`, detail: 'quantity must be a positive integer' });
    }
    out.push({ bookId: bookId as number, quantity: quantity as number });
  });
  if (problems.length) throw invalid(problems);
  return out;
}

type PaymentOutcome = 'accepted' | 'declined' | 'failed';

async function requestPayment(customerId: number, amountCents: number): Promise<PaymentOutcome> {
  try {
    const res = await fetch(PAYMENT_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ customerId, amountCents }),
      signal: AbortSignal.timeout(5000),
    });
    if (!res.ok) return 'failed';
    const body = (await res.json()) as { status?: unknown };
    if (body.status === 'accepted') return 'accepted';
    if (body.status === 'declined') return 'declined';
    return 'failed';
  } catch {
    return 'failed';
  }
}

@Controller('purchases')
export class PurchasesController {
  constructor(private readonly db: Db) {}

  @Get()
  async list(@Req() req: Request, @Res({ passthrough: true }) res: Response) {
    const customerId = await currentCustomer(this.db, req);
    const sp = searchParams(req);
    const page = pageParams(sp);
    includeParam(sp, []);
    const fields = fieldsParam(sp);
    const sort = sortParam(sp, PURCHASE_SORT);
    const order = sort.length ? [...sort, 'p.id ASC'] : ['p.created_at DESC', 'p.id DESC'];

    const total = (
      await this.db.query<{ n: number }>('SELECT count(*)::int AS n FROM purchase WHERE customer_id = $1', [customerId])
    ).rows[0].n;
    const { rows } = await this.db.query<PurchaseRow>(
      `SELECT p.id, p.created_at, p.total_cents FROM purchase p
        WHERE p.customer_id = $1
        ORDER BY ${order.join(', ')}
        LIMIT $2 OFFSET $3`,
      [customerId, page.size, page.offset],
    );
    const lines = await linesFor(
      this.db,
      rows.map((r) => r.id),
    );
    const data = rows.map((r) => purchaseResource(r, lines.get(r.id) ?? []));
    res.type('application/vnd.api+json');
    return listDocument('/purchases', sp, page, total, data, fields);
  }

  @Get(':id')
  async one(@Param('id') id: string, @Req() req: Request) {
    const customerId = await currentCustomer(this.db, req);
    const sp = searchParams(req);
    includeParam(sp, []);
    const fields = fieldsParam(sp);
    const purchaseId = numericId(id);
    if (purchaseId === undefined) throw notFound('purchase not found');
    const { rows } = await this.db.query<PurchaseRow>(
      'SELECT id, created_at, total_cents FROM purchase WHERE id = $1 AND customer_id = $2',
      [purchaseId, customerId],
    );
    if (rows.length === 0) throw notFound('purchase not found');
    const lines = await linesFor(this.db, [purchaseId]);
    return singleDocument(purchaseResource(rows[0], lines.get(purchaseId) ?? []), fields);
  }

  @Post()
  async create(@Req() req: Request, @Res({ passthrough: true }) res: Response) {
    const customerId = await currentCustomer(this.db, req);
    const body = requireJsonBodyObject(req);
    const lines = parseLines(body);

    const client = await this.db.pool.connect();
    try {
      await client.query('BEGIN');
      const bookIds = [...new Set(lines.map((l) => l.bookId))];
      const found = await client.query<BookStockRow>(
        `SELECT id, title, price_cents, stock FROM book
          WHERE published AND id = ANY($1::int[]) ORDER BY id FOR UPDATE`,
        [bookIds],
      );
      const books = new Map(found.rows.map((b) => [b.id, b]));

      const missing = lines
        .map((l, i) => ({ l, i }))
        .filter(({ l }) => !books.has(l.bookId))
        .map(({ i }) => ({
          pointer: `/data/attributes/lines/${i}/bookId`,
          detail: 'book does not exist or is not published',
        }));
      if (missing.length) throw invalid(missing);

      const wanted = new Map<number, number>();
      for (const l of lines) wanted.set(l.bookId, (wanted.get(l.bookId) ?? 0) + l.quantity);
      const short = [...wanted.entries()].filter(([id, qty]) => qty > (books.get(id) as BookStockRow).stock);
      if (short.length) {
        throw new ApiError(
          409,
          short.map(([id, qty]) => ({
            status: '409',
            title: 'Conflict',
            detail: `not enough stock for book ${id}: ${qty} requested, ${(books.get(id) as BookStockRow).stock} available`,
            source: { pointer: '/data/attributes/lines' },
          })),
        );
      }

      const total = lines.reduce((sum, l) => sum + l.quantity * (books.get(l.bookId) as BookStockRow).price_cents, 0);
      const payment = await requestPayment(customerId, total);
      if (payment === 'declined') throw fail(402, 'payment was declined');
      if (payment === 'failed') throw fail(502, 'payment service unavailable');

      const purchase = await client.query<{ id: number; created_at: string }>(
        'INSERT INTO purchase (customer_id, created_at, total_cents) VALUES ($1, now(), $2) RETURNING id, created_at',
        [customerId, total],
      );
      const purchaseId = purchase.rows[0].id;
      for (const l of lines) {
        await client.query(
          'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES ($1, $2, $3, $4)',
          [purchaseId, l.bookId, l.quantity, (books.get(l.bookId) as BookStockRow).price_cents],
        );
      }
      for (const [bookId, qty] of wanted) {
        await client.query('UPDATE book SET stock = stock - $2 WHERE id = $1', [bookId, qty]);
      }
      await client.query('COMMIT');

      const created: PurchaseRow = { id: purchaseId, created_at: purchase.rows[0].created_at, total_cents: total };
      const createdLines: LineRow[] = lines.map((l) => {
        const b = books.get(l.bookId) as BookStockRow;
        return {
          purchase_id: purchaseId,
          book_id: l.bookId,
          quantity: l.quantity,
          unit_price_cents: b.price_cents,
          title: b.title,
        };
      });
      res.status(201);
      res.setHeader('Location', `/purchases/${purchaseId}`);
      return singleDocument(purchaseResource(created, createdLines), {});
    } catch (err) {
      await client.query('ROLLBACK');
      throw err;
    } finally {
      client.release();
    }
  }
}
