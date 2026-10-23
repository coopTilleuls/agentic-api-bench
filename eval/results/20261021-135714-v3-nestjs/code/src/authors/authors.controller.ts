import { Controller, Get, Param, Req } from '@nestjs/common';
import type { Request } from 'express';
import { notFound } from '../common/errors.js';
import { parseId, QueryParams } from '../common/query.js';
import {
  listDocument,
  pageLinks,
  singleDocument,
  uniqueResources,
} from '../common/jsonapi.js';
import { bookResource, BooksService } from '../books/books.service.js';
import {
  AUTHOR_SORT,
  authorResource,
  AuthorsService,
} from './authors.service.js';

@Controller('authors')
export class AuthorsController {
  constructor(
    private readonly authors: AuthorsService,
    private readonly books: BooksService,
  ) {}

  @Get()
  async list(@Req() req: Request) {
    const query = new QueryParams(req.originalUrl);
    const page = query.pagination();
    const sort = query.sort(AUTHOR_SORT);
    const include = query.include(['books']);
    const { authors, total } = await this.authors.list(page, sort);

    const withBooks = include.includes('books');
    const books = withBooks
      ? await this.books.publishedByAuthors(authors.map((author) => author.id))
      : [];
    const included = withBooks
      ? uniqueResources(books.map(bookResource))
      : undefined;

    return listDocument(
      pageLinks(req.path, query.search, page.number, page.size, total),
      authors.map((author) =>
        authorResource(
          author,
          withBooks
            ? books.filter((book) => book.author_id === author.id)
            : undefined,
        ),
      ),
      total,
      included,
    );
  }

  @Get(':id')
  async one(@Param('id') rawId: string, @Req() req: Request) {
    const query = new QueryParams(req.originalUrl);
    const include = query.include(['books']);
    const id = parseId(rawId);
    const author =
      id === undefined ? undefined : await this.authors.findOne(id);
    if (!author) {
      throw notFound('Author not found');
    }

    const books = await this.books.publishedByAuthors([author.id]);
    const included = include.includes('books')
      ? books.map(bookResource)
      : undefined;
    return singleDocument(
      `/authors/${author.id}`,
      authorResource(author, books),
      included,
    );
  }
}
