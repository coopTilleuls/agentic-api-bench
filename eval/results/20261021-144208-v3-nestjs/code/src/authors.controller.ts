import { Controller, Get, Param, Req, Res } from '@nestjs/common';
import type { Request, Response } from 'express';
import { Db } from './db.js';
import {
  Fieldsets,
  fieldsParam,
  includeParam,
  included,
  listDocument,
  MAX_INT4,
  notFound,
  pageParams,
  Resource,
  searchParams,
  singleDocument,
  sortParam,
} from './jsonapi.js';
import { AuthorRow, authorResource, bookResource, groupBookIds, publishedBooks } from './resources.js';

const AUTHOR_SORT = { name: 'a.name', publishedBooks: 'published_books' };

@Controller('authors')
export class AuthorsController {
  constructor(private readonly db: Db) {}

  @Get()
  async list(@Req() req: Request, @Res({ passthrough: true }) res: Response) {
    const sp = searchParams(req);
    const page = pageParams(sp);
    const includes = includeParam(sp, ['books']);
    const fields = fieldsParam(sp);
    const sort = sortParam(sp, AUTHOR_SORT);

    const total = (await this.db.query<{ n: number }>('SELECT count(*)::int AS n FROM author')).rows[0].n;
    const { rows } = await this.db.query<AuthorRow>(
      `SELECT a.id, a.name,
              (SELECT count(*)::int FROM book b WHERE b.author_id = a.id AND b.published) AS published_books
         FROM author a
        ORDER BY ${[...sort, 'a.id ASC'].join(', ')}
        LIMIT $1 OFFSET $2`,
      [page.size, page.offset],
    );

    const ids = rows.map((r) => r.id);
    const books = ids.length ? await publishedBooks(this.db, 'b.author_id = ANY($1::int[])', [ids]) : [];
    const bookIds = groupBookIds(books);
    const data = rows.map((r) => authorResource(r, bookIds.get(r.id) ?? []));
    const inc = includes.length
      ? included(
          books.map((b) => bookResource(b)),
          fields,
        )
      : undefined;

    res.type('application/vnd.api+json');
    return listDocument('/authors', sp, page, total, data, fields, inc);
  }

  @Get(':id')
  async one(@Param('id') id: string, @Req() req: Request) {
    const sp = searchParams(req);
    const includes = includeParam(sp, ['books']);
    const fields: Fieldsets = fieldsParam(sp);
    const authorId = numericId(id);
    if (authorId === undefined) throw notFound('author not found');

    const { rows } = await this.db.query<AuthorRow>(
      `SELECT a.id, a.name,
              (SELECT count(*)::int FROM book b WHERE b.author_id = a.id AND b.published) AS published_books
         FROM author a WHERE a.id = $1`,
      [authorId],
    );
    if (rows.length === 0) throw notFound('author not found');

    const books = await publishedBooks(this.db, 'b.author_id = $1', [authorId]);
    const data: Resource = authorResource(
      rows[0],
      books.map((b) => b.id),
    );
    const inc = includes.length ? included(books.map((b) => bookResource(b)), fields) : undefined;
    return singleDocument(data, fields, inc);
  }
}

// Path ids that cannot be a database id answer 404 like any other unknown id.
export function numericId(id: string): number | undefined {
  if (!/^\d+$/.test(id)) return undefined;
  const n = Number(id);
  return n >= 1 && n <= MAX_INT4 ? n : undefined;
}
