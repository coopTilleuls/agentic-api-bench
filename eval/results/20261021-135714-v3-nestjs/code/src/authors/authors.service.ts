import { Inject, Injectable } from '@nestjs/common';
import pg from 'pg';
import { PG_POOL } from '../database/database.module.js';
import { Pagination } from '../common/query.js';
import { ResourceObject } from '../common/jsonapi.js';
import { BookRow } from '../books/books.service.js';

export interface AuthorRow {
  id: number;
  name: string | null;
  published_books: number;
}

export const AUTHOR_SORT: Record<string, string> = {
  name: 'a.name',
  publishedBooks: 'published_books',
};

export function authorResource(
  author: AuthorRow,
  books?: BookRow[],
): ResourceObject {
  return {
    type: 'authors',
    id: String(author.id),
    attributes: { name: author.name, publishedBooks: author.published_books },
    ...(books
      ? {
          relationships: {
            books: {
              data: books.map((book) => ({
                type: 'books',
                id: String(book.id),
              })),
            },
          },
        }
      : {}),
    links: { self: `/authors/${author.id}` },
  };
}

const PUBLISHED_BOOKS_COLUMN = `(SELECT count(*)::int FROM book b WHERE b.author_id = a.id AND b.published) AS published_books`;

@Injectable()
export class AuthorsService {
  constructor(@Inject(PG_POOL) private readonly pool: pg.Pool) {}

  async list(
    page: Pagination,
    sort: string[],
  ): Promise<{ authors: AuthorRow[]; total: number }> {
    const rows = await this.pool.query<AuthorRow>(
      `SELECT a.id, a.name, ${PUBLISHED_BOOKS_COLUMN}
         FROM author a
        ORDER BY ${[...sort, 'a.id ASC'].join(', ')}
        LIMIT ${page.size} OFFSET ${page.offset}`,
    );
    const count = await this.pool.query<{ count: number }>(
      'SELECT count(*)::int AS count FROM author',
    );
    return { authors: rows.rows, total: count.rows[0].count };
  }

  async findOne(id: number): Promise<AuthorRow | undefined> {
    const result = await this.pool.query<AuthorRow>(
      `SELECT a.id, a.name, ${PUBLISHED_BOOKS_COLUMN} FROM author a WHERE a.id = $1`,
      [id],
    );
    return result.rows[0];
  }
}
