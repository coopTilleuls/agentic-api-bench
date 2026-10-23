import { Injectable } from '@nestjs/common';
import { pool } from '../common/db.js';
import { AuthorRow, BookRow } from '../common/jsonapi.js';

const AUTHOR_SELECT = `a.id, a.name,
  (SELECT count(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books`;

@Injectable()
export class AuthorsService {
  async list(limit: number, offset: number): Promise<{ rows: AuthorRow[]; total: number }> {
    const total = await pool.query<{ total: number }>('SELECT count(*) AS total FROM author');
    const rows = await pool.query<AuthorRow>(
      `SELECT ${AUTHOR_SELECT} FROM author a ORDER BY a.id LIMIT $1 OFFSET $2`,
      [limit, offset],
    );
    return { rows: rows.rows, total: total.rows[0].total };
  }

  async find(id: number): Promise<AuthorRow | undefined> {
    const { rows } = await pool.query<AuthorRow>(`SELECT ${AUTHOR_SELECT} FROM author a WHERE a.id = $1`, [id]);
    return rows[0];
  }

  async findMany(ids: number[]): Promise<AuthorRow[]> {
    const { rows } = await pool.query<AuthorRow>(
      `SELECT ${AUTHOR_SELECT} FROM author a WHERE a.id = ANY($1::int[]) ORDER BY a.id`,
      [ids],
    );
    return rows;
  }

  /** Published books of the given authors, in id order. */
  async publishedBooks(authorIds: number[]): Promise<BookRow[]> {
    const { rows } = await pool.query<BookRow>(
      `SELECT b.id, b.title, b.author_id, b.price_cents, to_char(b.published_at, 'YYYY-MM-DD') AS published_at,
              b.stock, b.published
         FROM book b
        WHERE b.published AND b.author_id = ANY($1::int[])
        ORDER BY b.id`,
      [authorIds],
    );
    return rows;
  }
}
