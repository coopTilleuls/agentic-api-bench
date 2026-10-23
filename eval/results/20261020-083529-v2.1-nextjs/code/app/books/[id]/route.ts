import { pool } from '../../../lib/db';
import { bookJson, BOOK_SELECT, findPublishedBook, type BookRow } from '../../../lib/books';
import { fail, handle, HttpError, isPlainObject, parseId, readJson, INT_MAX } from '../../../lib/http';

export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

async function bookIdFrom(params: Params['params']): Promise<number | null> {
  return parseId((await params).id);
}

export function GET(_request: Request, { params }: Params) {
  return handle(async () => {
    const id = await bookIdFrom(params);
    const book = id === null ? null : await findPublishedBook(id);
    if (!book) return fail(404, 'Book not found');
    return Response.json(bookJson(book));
  });
}

function isIntegerIn(value: unknown, min: number, max: number): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= min && value <= max;
}

export function PATCH(request: Request, { params }: Params) {
  return handle(async () => {
    const id = await bookIdFrom(params);
    if (id === null) return fail(404, 'Book not found');
    const { rows: existing } = await pool.query<{ id: number; published: boolean }>(
      'select id, published from book where id = $1::int',
      [id],
    );
    if (existing.length === 0) return fail(404, 'Book not found');

    const body = await readJson(request);
    if (!isPlainObject(body)) throw new HttpError(422, 'Body must be a JSON object');

    const changes: { title: string | null; priceCents: number | null; stock: number | null } = {
      title: null,
      priceCents: null,
      stock: null,
    };
    if ('title' in body) {
      if (typeof body.title !== 'string' || body.title.trim() === '') {
        throw new HttpError(422, 'title must be a non-empty string');
      }
      changes.title = body.title;
    }
    if ('priceCents' in body) {
      if (!isIntegerIn(body.priceCents, 0, INT_MAX)) {
        throw new HttpError(422, 'priceCents must be a non-negative integer');
      }
      changes.priceCents = body.priceCents;
    }
    if ('stock' in body) {
      if (!isIntegerIn(body.stock, 0, INT_MAX)) {
        throw new HttpError(422, 'stock must be a non-negative integer');
      }
      changes.stock = body.stock;
    }

    await pool.query(
      `update book set
         title = coalesce($2::text, title),
         price_cents = coalesce($3::int, price_cents),
         stock = coalesce($4::int, stock)
       where id = $1::int`,
      [id, changes.title, changes.priceCents, changes.stock],
    );

    if (!existing[0].published) return new Response(null, { status: 204 });
    const { rows } = await pool.query<BookRow>(`${BOOK_SELECT} where b.id = $1::int`, [id]);
    return Response.json(bookJson(rows[0]));
  });
}
