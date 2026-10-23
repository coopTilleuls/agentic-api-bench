import { Controller, Get, Param, Patch, Req, Res } from '@nestjs/common';
import type { Request, Response } from 'express';
import { notFound } from '../common/errors.js';
import { parseId, QueryParams } from '../common/query.js';
import {
  listDocument,
  pageLinks,
  requestData,
  singleDocument,
  uniqueResources,
} from '../common/jsonapi.js';
import { parseBookPatch } from './book.input.js';
import {
  authorIncluded,
  BOOK_SORT,
  bookResource,
  BooksService,
} from './books.service.js';

@Controller('books')
export class BooksController {
  constructor(private readonly books: BooksService) {}

  @Get()
  async list(@Req() req: Request) {
    const query = new QueryParams(req.originalUrl);
    const page = query.pagination();
    const sort = query.sort(BOOK_SORT);
    const include = query.include(['author']);
    const { books, total } = await this.books.list(query, page, sort);

    let included;
    if (include.includes('author')) {
      const authors = await this.books.authorsByIds(
        books.map((book) => book.author_id),
      );
      included = authors.map(authorIncluded);
    }
    return listDocument(
      pageLinks(req.path, query.search, page.number, page.size, total),
      books.map(bookResource),
      total,
      included && uniqueResources(included),
    );
  }

  @Get(':id')
  async one(@Param('id') rawId: string, @Req() req: Request) {
    const query = new QueryParams(req.originalUrl);
    const include = query.include(['author']);
    const id = parseId(rawId);
    const book =
      id === undefined ? undefined : await this.books.findPublished(id);
    if (!book) {
      throw notFound('Book not found');
    }

    let included;
    if (include.includes('author')) {
      const authors = await this.books.authorsByIds([book.author_id]);
      included = authors.map(authorIncluded);
    }
    return singleDocument(`/books/${book.id}`, bookResource(book), included);
  }

  @Patch(':id')
  async update(
    @Param('id') rawId: string,
    @Req() req: Request,
    @Res({ passthrough: true }) res: Response,
  ) {
    const id = parseId(rawId);
    if (id === undefined) {
      throw notFound('Book not found');
    }
    const data = requestData(req);
    const changes = parseBookPatch(data, id);
    const book = await this.books.update(id, changes);
    if (!book) {
      throw notFound('Book not found');
    }
    if (!book.published) {
      res.status(204);
      return;
    }
    return singleDocument(`/books/${book.id}`, bookResource(book));
  }
}
