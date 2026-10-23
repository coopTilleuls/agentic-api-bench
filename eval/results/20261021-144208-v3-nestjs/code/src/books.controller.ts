import { Controller, Get, Param, Patch, Req, Res } from '@nestjs/common';
import type { Request, Response } from 'express';
import { Db } from './db.js';
import { numericId } from './authors.controller.js';
import {
  boolFilter,
  dateFilter,
  fail,
  fieldsParam,
  includeParam,
  included,
  intFilter,
  invalid,
  isObject,
  jsonBody,
  listDocument,
  notFound,
  pageParams,
  requireJsonApi,
  searchParams,
  single,
  singleDocument,
  sortParam,
} from './jsonapi.js';
import { BookRow, bookResource, authorsByIds, BOOK_COLUMNS } from './resources.js';

const BOOK_SORT = { priceCents: 'b.price_cents', title: 'b.title', publishedAt: 'b.published_at' };

@Controller('books')
export class BooksController {
  constructor(private readonly db: Db) {}

  @Get()
  async list(@Req() req: Request, @Res({ passthrough: true }) res: Response) {
    const sp = searchParams(req);
    const page = pageParams(sp);
    const includes = includeParam(sp, ['author']);
    const fields = fieldsParam(sp);
    const sort = sortParam(sp, BOOK_SORT);

    const conds = ['b.published'];
    const params: unknown[] = [];
    const add = (sql: string, value?: unknown) => {
      if (value !== undefined) params.push(value);
      conds.push(sql.replace('?', `$${params.length}`));
    };

    const title = single(sp, 'filter[title]');
    if (title !== undefined) add('strpos(lower(b.title), lower(?)) > 0', title);
    const author = single(sp, 'filter[author]');
    if (author !== undefined) add('a.name = ?', author);
    const minPrice = intFilter(sp, 'filter[minPrice]');
    if (minPrice !== undefined) add('b.price_cents >= ?', minPrice);
    const maxPrice = intFilter(sp, 'filter[maxPrice]');
    if (maxPrice !== undefined) add('b.price_cents <= ?', maxPrice);
    const after = dateFilter(sp, 'filter[publishedAfter]');
    if (after !== undefined) add('b.published_at >= ?::date', after);
    const before = dateFilter(sp, 'filter[publishedBefore]');
    if (before !== undefined) add('b.published_at <= ?::date', before);
    const inStock = boolFilter(sp, 'filter[inStock]');
    if (inStock !== undefined) conds.push(inStock ? 'b.stock > 0' : 'b.stock = 0');

    const from = `FROM book b JOIN author a ON a.id = b.author_id WHERE ${conds.join(' AND ')}`;
    const total = (await this.db.query<{ n: number }>(`SELECT count(*)::int AS n ${from}`, params)).rows[0].n;
    const { rows } = await this.db.query<BookRow>(
      `SELECT ${BOOK_COLUMNS} ${from}
        ORDER BY ${[...sort, 'b.id ASC'].join(', ')}
        LIMIT $${params.length + 1} OFFSET $${params.length + 2}`,
      [...params, page.size, page.offset],
    );

    const data = rows.map((r) => bookResource(r));
    const inc = includes.length ? included(await authorsByIds(this.db, [...new Set(rows.map((r) => r.author_id))]), fields) : undefined;
    res.type('application/vnd.api+json');
    return listDocument('/books', sp, page, total, data, fields, inc);
  }

  @Get(':id')
  async one(@Param('id') id: string, @Req() req: Request) {
    const sp = searchParams(req);
    const includes = includeParam(sp, ['author']);
    const fields = fieldsParam(sp);
    const row = await this.findPublished(id);
    const inc = includes.length ? included(await authorsByIds(this.db, [row.author_id]), fields) : undefined;
    return singleDocument(bookResource(row), fields, inc);
  }

  @Patch(':id')
  async update(@Param('id') id: string, @Req() req: Request, @Res({ passthrough: true }) res: Response) {
    requireJsonApi(req);
    const body = jsonBody(req);
    const bookId = numericId(id);
    if (bookId === undefined) throw notFound('book not found');
    const existing = await this.db.query<{ published: boolean }>('SELECT published FROM book WHERE id = $1', [bookId]);
    if (existing.rowCount === 0) throw notFound('book not found');

    const data = isObject(body) ? body.data : undefined;
    if (!isObject(data)) throw invalid([{ pointer: '/data', detail: 'data must be an object' }]);
    if (data.type !== 'books' || (data.id !== undefined && String(data.id) !== id)) {
      throw fail(409, 'data.type and data.id must match the resource', { pointer: '/data' });
    }
    const attrs = data.attributes ?? {};
    if (!isObject(attrs)) throw invalid([{ pointer: '/data/attributes', detail: 'attributes must be an object' }]);

    const problems: { pointer: string; detail: string }[] = [];
    const sets: string[] = [];
    const values: unknown[] = [];
    const set = (column: string, value: unknown) => {
      values.push(value);
      sets.push(`${column} = $${values.length}`);
    };
    if ('title' in attrs) {
      if (typeof attrs.title !== 'string' || attrs.title.trim() === '') {
        problems.push({ pointer: '/data/attributes/title', detail: 'title must be a non-empty string' });
      } else {
        set('title', attrs.title);
      }
    }
    for (const [name, column] of [
      ['priceCents', 'price_cents'],
      ['stock', 'stock'],
    ] as const) {
      if (!(name in attrs)) continue;
      const v = attrs[name];
      if (!Number.isInteger(v) || (v as number) < 0 || (v as number) > 2147483647) {
        problems.push({ pointer: `/data/attributes/${name}`, detail: `${name} must be a non-negative integer` });
      } else {
        set(column, v);
      }
    }
    if (problems.length) throw invalid(problems);

    if (sets.length) {
      values.push(bookId);
      await this.db.query(`UPDATE book SET ${sets.join(', ')} WHERE id = $${values.length}`, values);
    }
    if (!existing.rows[0].published) {
      res.removeHeader('Content-Type');
      res.status(204);
      return;
    }
    const row = await this.findPublished(id);
    return singleDocument(bookResource(row), {});
  }

  private async findPublished(id: string): Promise<BookRow> {
    const bookId = numericId(id);
    if (bookId === undefined) throw notFound('book not found');
    const { rows } = await this.db.query<BookRow>(
      `SELECT ${BOOK_COLUMNS} FROM book b WHERE b.id = $1 AND b.published`,
      [bookId],
    );
    if (rows.length === 0) throw notFound('book not found');
    return rows[0];
  }
}
