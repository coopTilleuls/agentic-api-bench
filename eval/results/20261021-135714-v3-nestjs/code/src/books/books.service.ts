import { Inject, Injectable } from '@nestjs/common';
import pg from 'pg';
import { PG_POOL } from '../database/database.module.js';
import { escapeLike, QueryParams, Pagination } from '../common/query.js';
import { ResourceObject } from '../common/jsonapi.js';

export interface BookRow {
  id: number;
  title: string;
  author_id: number;
  published: boolean;
  price_cents: number;
  published_at: string | null;
  stock: number;
}

export interface AuthorRow {
  id: number;
  name: string | null;
}

export interface BookChanges {
  title?: string;
  priceCents?: number;
  stock?: number;
}

export const BOOK_SORT: Record<string, string> = {
  priceCents: 'b.price_cents',
  title: 'b.title',
  publishedAt: 'b.published_at',
};

// published_at is a DATE: formatted in SQL so no time zone conversion happens in Node
const BOOK_COLUMNS = `b.id, b.title, b.author_id, b.published, b.price_cents, b.stock,
  to_char(b.published_at, 'YYYY-MM-DD') AS published_at`;

export function bookResource(book: BookRow): ResourceObject {
  return {
    type: 'books',
    id: String(book.id),
    attributes: {
      title: book.title,
      priceCents: book.price_cents,
      publishedAt: book.published_at,
      stock: book.stock,
    },
    relationships: {
      author: { data: { type: 'authors', id: String(book.author_id) } },
    },
    links: { self: `/books/${book.id}` },
  };
}

export function authorIncluded(author: AuthorRow): ResourceObject {
  return {
    type: 'authors',
    id: String(author.id),
    attributes: { name: author.name },
    links: { self: `/authors/${author.id}` },
  };
}

@Injectable()
export class BooksService {
  constructor(@Inject(PG_POOL) private readonly pool: pg.Pool) {}

  async list(
    query: QueryParams,
    page: Pagination,
    sort: string[],
  ): Promise<{ books: BookRow[]; total: number }> {
    const args: unknown[] = [];
    const arg = (value: unknown): string => {
      args.push(value);
      return `$${args.length}`;
    };
    const where = ['b.published'];

    const title = query.filter('title');
    if (title) where.push(`b.title ILIKE ${arg(`%${escapeLike(title)}%`)}`);
    const author = query.filter('author');
    if (author !== undefined) where.push(`a.name = ${arg(author)}`);
    const minPrice = query.integerFilter('minPrice');
    if (minPrice !== undefined) where.push(`b.price_cents >= ${arg(minPrice)}`);
    const maxPrice = query.integerFilter('maxPrice');
    if (maxPrice !== undefined) where.push(`b.price_cents <= ${arg(maxPrice)}`);
    const publishedAfter = query.dateFilter('publishedAfter');
    if (publishedAfter !== undefined)
      where.push(`b.published_at >= ${arg(publishedAfter)}`);
    const publishedBefore = query.dateFilter('publishedBefore');
    if (publishedBefore !== undefined)
      where.push(`b.published_at <= ${arg(publishedBefore)}`);
    const inStock = query.booleanFilter('inStock');
    if (inStock !== undefined)
      where.push(inStock ? 'b.stock > 0' : 'b.stock = 0');

    const from = `FROM book b JOIN author a ON a.id = b.author_id WHERE ${where.join(' AND ')}`;
    const count = await this.pool.query<{ count: number }>(
      `SELECT count(*)::int AS count ${from}`,
      args,
    );
    const rows = await this.pool.query<BookRow>(
      `SELECT ${BOOK_COLUMNS} ${from} ORDER BY ${[...sort, 'b.id ASC'].join(', ')} LIMIT ${page.size} OFFSET ${page.offset}`,
      args,
    );
    return { books: rows.rows, total: count.rows[0].count };
  }

  async findPublished(id: number): Promise<BookRow | undefined> {
    const result = await this.pool.query<BookRow>(
      `SELECT ${BOOK_COLUMNS} FROM book b WHERE b.id = $1 AND b.published`,
      [id],
    );
    return result.rows[0];
  }

  async publishedByAuthors(authorIds: number[]): Promise<BookRow[]> {
    const result = await this.pool.query<BookRow>(
      `SELECT ${BOOK_COLUMNS} FROM book b WHERE b.published AND b.author_id = ANY($1::int[]) ORDER BY b.id`,
      [authorIds],
    );
    return result.rows;
  }

  async authorsByIds(ids: number[]): Promise<AuthorRow[]> {
    const result = await this.pool.query<AuthorRow>(
      'SELECT id, name FROM author WHERE id = ANY($1::int[]) ORDER BY id',
      [[...new Set(ids)]],
    );
    return result.rows;
  }

  /** Applies the given attributes; returns undefined when the book does not exist. */
  async update(id: number, changes: BookChanges): Promise<BookRow | undefined> {
    const result = await this.pool.query<BookRow>(
      `UPDATE book AS b
         SET title = COALESCE($2, b.title),
             price_cents = COALESCE($3, b.price_cents),
             stock = COALESCE($4, b.stock)
       WHERE b.id = $1
       RETURNING ${BOOK_COLUMNS}`,
      [
        id,
        changes.title ?? null,
        changes.priceCents ?? null,
        changes.stock ?? null,
      ],
    );
    return result.rows[0];
  }
}
