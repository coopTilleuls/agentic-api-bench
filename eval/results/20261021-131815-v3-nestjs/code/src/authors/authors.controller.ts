import { Controller, Get, Param, Req } from '@nestjs/common';
import type { Request } from 'express';
import { apiError } from '../common/errors.js';
import { pageLinks, parseId, parseInclude, parsePage, queryOf } from '../common/query.js';
import {
  authorResource,
  bookResource,
  dedupe,
  Document,
  ResourceObject,
} from '../common/jsonapi.js';
import { AuthorsService } from './authors.service.js';

@Controller('authors')
export class AuthorsController {
  constructor(private readonly authors: AuthorsService) {}

  @Get()
  async list(@Req() req: Request): Promise<Document> {
    const params = queryOf(req);
    const page = parsePage(params);
    const include = parseInclude(params, ['books']);
    const { rows, total } = await this.authors.list(page.size, (page.number - 1) * page.size);

    const included: ResourceObject[] = [];
    if (include.includes('books')) {
      const books = await this.authors.publishedBooks(rows.map((a) => a.id));
      included.push(...books.map(bookResource));
    }
    return {
      data: rows.map((a) => authorResource(a)),
      ...(include.length ? { included: dedupe(included) } : {}),
      links: pageLinks('/authors', params, page, total),
      meta: { total },
    };
  }

  @Get(':id')
  async one(@Param('id') rawId: string, @Req() req: Request): Promise<Document> {
    const params = queryOf(req);
    const include = parseInclude(params, ['books']);
    const id = parseId(rawId);
    const author = id === undefined ? undefined : await this.authors.find(id);
    if (!author) {
      throw apiError(404, `author ${rawId} not found`);
    }
    const books = await this.authors.publishedBooks([author.id]);
    return {
      data: authorResource(
        author,
        books.map((b) => b.id),
      ),
      ...(include.includes('books') ? { included: books.map(bookResource) } : {}),
      links: { self: `/authors/${author.id}` },
    };
  }
}
