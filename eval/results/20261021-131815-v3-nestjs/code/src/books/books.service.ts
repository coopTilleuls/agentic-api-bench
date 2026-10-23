import { Injectable } from '@nestjs/common';
import { pool } from '../common/db.js';
import { BookRow } from '../common/jsonapi.js';
import type { Page } from '../common/query.js';

export interface BookFilters {
  title?: string;
  author?: string;
  minPrice?: number;
  maxPrice?: number;
  publishedAfter?: string;
  publishedBefore?: string;
  inStock?: boolean;
}

const BOOK_SELECT = `b.id, b.title, b.author_id, b.price_cents,
  to_char(b.published_at, 'YYYY-MM-DD') AS published_at, b.stock, b.published`;

@Injectable()
export class BooksService {
  async list(
    filters: BookFilters,
    orderBy: string[],
    page: Page,
  ): Promise<{ rows: BookRow[]; total: number }> {
    const where: string[] = ['b.published'];
    const values: unknown[] = [];
    const add = (sql: string, value: unknown): void => {
      values.push(value);
      where.push(sql.replace('?', `$${values.length}`));
    };

    if (filters.title !== undefined) add('strpos(lower(b.title), lower(?)) > 0', filters.title);
    if (filters.author !== undefined) add('a.name = ?', filters.author);
    if (filters.minPrice !== undefined) add('b.price_cents >= ?', filters.minPrice);
    if (filters.maxPrice !== undefined) add('b.price_cents <= ?', filters.maxPrice);
    if (filters.publishedAfter !== undefined) add('b.published_at >= ?::date', filters.publishedAfter);
    if (filters.publishedBefore !== undefined) add('b.published_at <= ?::date', filters.publishedBefore);
    if (filters.inStock !== undefined) where.push(filters.inStock ? 'b.stock > 0' : 'b.stock = 0');

    const from = `FROM book b JOIN author a ON a.id = b.author_id WHERE ${where.join(' AND ')}`;
    const total = await pool.query<{ total: number }>(`SELECT count(*) AS total ${from}`, values);

    values.push(page.size, (page.number - 1) * page.size);
    const rows = await pool.query<BookRow>(
      `SELECT ${BOOK_SELECT} ${from}
        ORDER BY ${[...orderBy, 'b.id ASC'].join(', ')}
        LIMIT $${values.length - 1} OFFSET $${values.length}`,
      values,
    );
    return { rows: rows.rows, total: total.rows[0].total };
  }

  async findPublished(id: number): Promise<BookRow | undefined> {
    const { rows } = await pool.query<BookRow>(`SELECT ${BOOK_SELECT} FROM book b WHERE b.id = $1 AND b.published`, [
      id,
    ]);
    return rows[0];
  }

  /** Applies only the given columns; `null` keeps the current value. */
  async update(
    id: number,
    changes: { title: string | null; priceCents: number | null; stock: number | null },
  ): Promise<BookRow | undefined> {
    const { rows } = await pool.query<BookRow>(
      `UPDATE book
          SET title = COALESCE($2, title),
              price_cents = COALESCE($3, price_cents),
              stock = COALESCE($4, stock)
        WHERE id = $1
    RETURNING id, title, author_id, price_cents, to_char(published_at, 'YYYY-MM-DD') AS published_at,
              stock, published`,
      [id, changes.title, changes.priceCents, changes.stock],
    );
    return rows[0];
  }
}
