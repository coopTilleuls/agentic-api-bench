import { pool } from './db';
import { HttpError, parseBoolean, parseDate, parseInteger } from './http';

export type BookRow = {
  id: number;
  title: string;
  price_cents: number;
  published_at: string | null;
  stock: number;
  author_id: number;
  author_name: string | null;
};

export const BOOK_SELECT = `
  select b.id, b.title, b.price_cents, b.published_at, b.stock,
         b.author_id, a.name as author_name
  from book b
  join author a on a.id = b.author_id`;

export function bookJson(row: BookRow) {
  return {
    id: row.id,
    title: row.title,
    priceCents: row.price_cents,
    publishedAt: row.published_at,
    stock: row.stock,
    author: { id: row.author_id, name: row.author_name },
  };
}

const SORT_COLUMNS: Record<string, string> = {
  priceCents: 'b.price_cents',
  title: 'b.title',
  publishedAt: 'b.published_at',
};

export function parseBookFilters(searchParams: URLSearchParams) {
  const get = (name: string) => searchParams.get(name);
  const has = (name: string) => searchParams.has(name);
  const filters = {
    q: get('q'),
    title: get('title'),
    author: get('author'),
    priceGte: has('priceCents[gte]') ? parseInteger(get('priceCents[gte]')!, 'priceCents[gte]') : null,
    priceLte: has('priceCents[lte]') ? parseInteger(get('priceCents[lte]')!, 'priceCents[lte]') : null,
    publishedAfter: has('publishedAt[after]')
      ? parseDate(get('publishedAt[after]')!, 'publishedAt[after]')
      : null,
    publishedBefore: has('publishedAt[before]')
      ? parseDate(get('publishedAt[before]')!, 'publishedAt[before]')
      : null,
    inStock: has('inStock') ? parseBoolean(get('inStock')!, 'inStock') : null,
  };

  const orders: string[] = [];
  for (const [key, value] of searchParams) {
    const match = /^order\[(\w+)\]$/.exec(key);
    if (!match) continue;
    const column = SORT_COLUMNS[match[1]];
    if (!column) throw new HttpError(400, `Unknown sort field ${match[1]}`);
    if (value !== 'asc' && value !== 'desc') {
      throw new HttpError(400, 'Sort direction must be asc or desc');
    }
    orders.push(`${column} ${value}`);
  }
  orders.push('b.id asc');
  return { filters, orders };
}

/** Builds the WHERE clause for the published books matching the filters. */
export function bookWhere(filters: ReturnType<typeof parseBookFilters>['filters']) {
  const conditions = ['b.published'];
  const params: unknown[] = [];
  const param = (value: unknown) => {
    params.push(value);
    return `$${params.length}`;
  };

  if (filters.q !== null) {
    const text = param(filters.q);
    conditions.push(
      `(position(lower(${text}::text) in lower(b.title)) > 0 or position(lower(${text}::text) in lower(a.name)) > 0)`,
    );
  }
  if (filters.title !== null) {
    conditions.push(`position(lower(${param(filters.title)}::text) in lower(b.title)) > 0`);
  }
  if (filters.author !== null) {
    conditions.push(`a.name = ${param(filters.author)}::text`);
  }
  if (filters.priceGte !== null) {
    conditions.push(`b.price_cents >= ${param(filters.priceGte)}::bigint`);
  }
  if (filters.priceLte !== null) {
    conditions.push(`b.price_cents <= ${param(filters.priceLte)}::bigint`);
  }
  if (filters.publishedAfter !== null) {
    conditions.push(`b.published_at >= ${param(filters.publishedAfter)}::date`);
  }
  if (filters.publishedBefore !== null) {
    conditions.push(`b.published_at <= ${param(filters.publishedBefore)}::date`);
  }
  if (filters.inStock === true) conditions.push('b.stock > 0');
  if (filters.inStock === false) conditions.push('b.stock = 0');

  return { sql: conditions.join(' and '), params };
}

export async function findPublishedBook(id: number): Promise<BookRow | null> {
  const { rows } = await pool.query<BookRow>(
    `${BOOK_SELECT} where b.id = $1::int and b.published`,
    [id],
  );
  return rows[0] ?? null;
}
