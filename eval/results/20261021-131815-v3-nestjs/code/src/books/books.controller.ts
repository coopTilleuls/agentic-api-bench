import { Body, Controller, Get, HttpCode, Param, Patch, Req, Res } from '@nestjs/common';
import type { Request, Response } from 'express';
import { AuthorsService } from '../authors/authors.service.js';
import { ApiError, apiError, ErrorObject } from '../common/errors.js';
import {
  authorResource,
  bookResource,
  Document,
  isObject,
  ResourceObject,
} from '../common/jsonapi.js';
import {
  pageLinks,
  parseBoolParam,
  parseDateParam,
  parseId,
  parseInclude,
  parseIntParam,
  parsePage,
  parseSort,
  queryOf,
} from '../common/query.js';
import { MAX_INT } from '../common/db.js';
import { BookFilters, BooksService } from './books.service.js';

const BOOK_SORT: Record<string, string> = {
  priceCents: 'b.price_cents',
  title: 'b.title',
  publishedAt: 'b.published_at',
};

function isJsonApi(req: Request): boolean {
  const type = req.headers['content-type'] ?? '';
  return type.split(';')[0].trim().toLowerCase() === 'application/vnd.api+json';
}

function isNonNegativeInt(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= MAX_INT;
}

function notFound(rawId: string): never {
  throw apiError(404, `book ${rawId} not found`);
}

@Controller('books')
export class BooksController {
  constructor(
    private readonly books: BooksService,
    private readonly authors: AuthorsService,
  ) {}

  @Get()
  async list(@Req() req: Request): Promise<Document> {
    const params = queryOf(req);
    const page = parsePage(params);
    const include = parseInclude(params, ['author']);
    const filters: BookFilters = {
      title: params.get('filter[title]') ?? undefined,
      author: params.get('filter[author]') ?? undefined,
      minPrice: parseIntParam(params, 'filter[minPrice]'),
      maxPrice: parseIntParam(params, 'filter[maxPrice]'),
      publishedAfter: parseDateParam(params, 'filter[publishedAfter]'),
      publishedBefore: parseDateParam(params, 'filter[publishedBefore]'),
      inStock: parseBoolParam(params, 'filter[inStock]'),
    };
    const order = parseSort(params, BOOK_SORT);
    const { rows, total } = await this.books.list(filters, order, page);

    const included: ResourceObject[] = [];
    if (include.includes('author')) {
      const authors = await this.authors.findMany([...new Set(rows.map((b) => b.author_id))]);
      included.push(...authors.map((a) => authorResource(a)));
    }
    return {
      data: rows.map(bookResource),
      ...(include.length ? { included } : {}),
      links: pageLinks('/books', params, page, total),
      meta: { total },
    };
  }

  @Get(':id')
  async one(@Param('id') rawId: string, @Req() req: Request): Promise<Document> {
    const include = parseInclude(queryOf(req), ['author']);
    const id = parseId(rawId);
    const book = id === undefined ? undefined : await this.books.findPublished(id);
    if (!book) notFound(rawId);

    const included: ResourceObject[] = [];
    if (include.includes('author')) {
      const authors = await this.authors.findMany([book.author_id]);
      included.push(...authors.map((a) => authorResource(a)));
    }
    return {
      data: bookResource(book),
      ...(include.length ? { included } : {}),
      links: { self: `/books/${book.id}` },
    };
  }

  @Patch(':id')
  @HttpCode(200)
  async update(
    @Param('id') rawId: string,
    @Req() req: Request,
    @Body() body: unknown,
    @Res({ passthrough: true }) res: Response,
  ): Promise<Document | undefined> {
    const id = parseId(rawId);
    if (id === undefined) notFound(rawId);
    if (!isJsonApi(req)) {
      throw apiError(415, 'Content-Type must be application/vnd.api+json');
    }

    const data = isObject(body) ? body.data : undefined;
    if (!isObject(data)) {
      throw new ApiError(422, [
        { status: '422', title: 'Unprocessable Entity', detail: 'data object is required', source: { pointer: '/data' } },
      ]);
    }
    if (data.type !== 'books') {
      throw apiError(409, 'resource type must be "books"', { pointer: '/data/type' });
    }
    if (data.id !== undefined && String(data.id) !== String(id)) {
      throw apiError(409, `data.id must match the path id ${rawId}`, { pointer: '/data/id' });
    }

    const attrs: unknown = data.attributes ?? {};
    if (!isObject(attrs)) {
      throw apiError(422, 'attributes must be an object', { pointer: '/data/attributes' });
    }
    const errors: ErrorObject[] = [];
    const invalid = (pointer: string, detail: string): void => {
      errors.push({ status: '422', title: 'Unprocessable Entity', detail, source: { pointer } });
    };
    const changes = { title: null as string | null, priceCents: null as number | null, stock: null as number | null };

    if (Object.hasOwn(attrs, 'title')) {
      const title = attrs.title;
      if (typeof title !== 'string' || title.trim() === '') {
        invalid('/data/attributes/title', 'title must be a non-empty string');
      } else {
        changes.title = title;
      }
    }
    if (Object.hasOwn(attrs, 'priceCents')) {
      if (isNonNegativeInt(attrs.priceCents)) changes.priceCents = attrs.priceCents;
      else invalid('/data/attributes/priceCents', 'priceCents must be a non-negative integer');
    }
    if (Object.hasOwn(attrs, 'stock')) {
      if (isNonNegativeInt(attrs.stock)) changes.stock = attrs.stock;
      else invalid('/data/attributes/stock', 'stock must be a non-negative integer');
    }
    if (errors.length) {
      throw new ApiError(422, errors);
    }

    const book = await this.books.update(id, changes);
    if (!book) notFound(rawId);
    if (!book.published) {
      res.status(204);
      return undefined;
    }
    return { data: bookResource(book), links: { self: `/books/${book.id}` } };
  }
}
