import type { Db } from './db.js';
import type { Resource } from './jsonapi.js';

export interface BookRow {
  id: number;
  title: string;
  author_id: number;
  price_cents: number;
  published_at: string | null;
  stock: number;
}

export interface AuthorRow {
  id: number;
  name: string | null;
  published_books?: number;
}

export const BOOK_COLUMNS = 'b.id, b.title, b.author_id, b.price_cents, b.published_at, b.stock';

export function bookResource(r: BookRow, authorLinkage = true): Resource {
  const out: Resource = {
    type: 'books',
    id: String(r.id),
    attributes: {
      title: r.title,
      priceCents: r.price_cents,
      publishedAt: r.published_at,
      stock: r.stock,
    },
    links: { self: `/books/${r.id}` },
  };
  if (authorLinkage) {
    out.relationships = { author: { data: { type: 'authors', id: String(r.author_id) } } };
  }
  return out;
}

export function authorResource(r: AuthorRow, bookIds?: number[]): Resource {
  const attributes: Record<string, unknown> = { name: r.name };
  if (r.published_books !== undefined) attributes.publishedBooks = r.published_books;
  const out: Resource = {
    type: 'authors',
    id: String(r.id),
    attributes,
    links: { self: `/authors/${r.id}` },
  };
  if (bookIds) {
    out.relationships = {
      books: { data: bookIds.map((id) => ({ type: 'books', id: String(id) })) },
    };
  }
  return out;
}

// Published books matching a condition on the book table, in id order.
export async function publishedBooks(db: Db, where: string, params: unknown[]): Promise<BookRow[]> {
  const { rows } = await db.query<BookRow>(
    `SELECT ${BOOK_COLUMNS} FROM book b WHERE b.published AND ${where} ORDER BY b.id`,
    params,
  );
  return rows;
}

export async function authorsByIds(db: Db, ids: number[]): Promise<Resource[]> {
  if (ids.length === 0) return [];
  const { rows } = await db.query<AuthorRow>(
    'SELECT id, name FROM author WHERE id = ANY($1::int[]) ORDER BY id',
    [ids],
  );
  return rows.map((r) => authorResource(r));
}

export function groupBookIds(rows: BookRow[]): Map<number, number[]> {
  const map = new Map<number, number[]>();
  for (const r of rows) {
    const list = map.get(r.author_id) ?? [];
    list.push(r.id);
    map.set(r.author_id, list);
  }
  return map;
}
