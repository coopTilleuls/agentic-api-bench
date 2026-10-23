import { Body, Controller, Get, Headers, Param, Post, Req, Res } from '@nestjs/common';
import type { Request, Response } from 'express';
import { apiError, ApiError, ErrorObject } from '../common/errors.js';
import { Document, isObject, purchaseResource } from '../common/jsonapi.js';
import { pageLinks, parseId, parseInclude, parsePage, queryOf } from '../common/query.js';
import { authenticateCustomer, PurchaseLineInput, PurchasesService } from './purchases.service.js';

function isJsonApi(req: Request): boolean {
  const type = req.headers['content-type'] ?? '';
  return type.split(';')[0].trim().toLowerCase() === 'application/vnd.api+json';
}

function isPositiveInt(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= 1;
}

function parseLines(body: unknown): PurchaseLineInput[] {
  const data = isObject(body) ? body.data : undefined;
  if (!isObject(data)) {
    throw new ApiError(422, [
      { status: '422', title: 'Unprocessable Entity', detail: 'data object is required', source: { pointer: '/data' } },
    ]);
  }
  if (data.type !== 'purchases') {
    throw apiError(409, 'resource type must be "purchases"', { pointer: '/data/type' });
  }
  const attrs = isObject(data.attributes) ? data.attributes : {};
  const lines = attrs.lines;
  if (!Array.isArray(lines) || lines.length === 0) {
    throw new ApiError(422, [
      {
        status: '422',
        title: 'Unprocessable Entity',
        detail: 'lines must be a non-empty array',
        source: { pointer: '/data/attributes/lines' },
      },
    ]);
  }

  const errors: ErrorObject[] = [];
  const invalid = (pointer: string, detail: string): void => {
    errors.push({ status: '422', title: 'Unprocessable Entity', detail, source: { pointer } });
  };
  const parsed: PurchaseLineInput[] = [];
  lines.forEach((line: unknown, i: number) => {
    const base = `/data/attributes/lines/${i}`;
    if (!isObject(line)) {
      invalid(base, 'line must be an object');
      return;
    }
    if (!isPositiveInt(line.bookId)) invalid(`${base}/bookId`, 'bookId must be an integer');
    if (!isPositiveInt(line.quantity)) invalid(`${base}/quantity`, 'quantity must be an integer of at least 1');
    if (isPositiveInt(line.bookId) && isPositiveInt(line.quantity)) {
      parsed.push({ bookId: line.bookId, quantity: line.quantity });
    }
  });
  if (errors.length) throw new ApiError(422, errors);
  return parsed;
}

@Controller('purchases')
export class PurchasesController {
  constructor(private readonly purchases: PurchasesService) {}

  @Get()
  async list(@Headers('customer-id') customerHeader: string | undefined, @Req() req: Request): Promise<Document> {
    const customerId = await authenticateCustomer(customerHeader);
    const params = queryOf(req);
    const page = parsePage(params);
    parseInclude(params, []);
    const { rows, total } = await this.purchases.list(customerId, page.size, (page.number - 1) * page.size);
    const lines = await this.purchases.lines(rows.map((p) => p.id));
    return {
      data: rows.map((p) => purchaseResource(p, lines.filter((l) => l.purchase_id === p.id))),
      links: pageLinks('/purchases', params, page, total),
      meta: { total },
    };
  }

  @Get(':id')
  async one(
    @Headers('customer-id') customerHeader: string | undefined,
    @Param('id') rawId: string,
    @Req() req: Request,
  ): Promise<Document> {
    const customerId = await authenticateCustomer(customerHeader);
    parseInclude(queryOf(req), []);
    const id = parseId(rawId);
    const purchase = id === undefined ? undefined : await this.purchases.find(customerId, id);
    if (!purchase) throw apiError(404, `purchase ${rawId} not found`);
    const lines = await this.purchases.lines([purchase.id]);
    return { data: purchaseResource(purchase, lines), links: { self: `/purchases/${purchase.id}` } };
  }

  @Post()
  async create(
    @Headers('customer-id') customerHeader: string | undefined,
    @Req() req: Request,
    @Body() body: unknown,
    @Res({ passthrough: true }) res: Response,
  ): Promise<Document> {
    const customerId = await authenticateCustomer(customerHeader);
    if (!isJsonApi(req)) {
      throw apiError(415, 'Content-Type must be application/vnd.api+json');
    }
    const lines = parseLines(body);
    const purchase = await this.purchases.create(customerId, lines);
    const documentLines = await this.purchases.lines([purchase.id]);
    res.status(201);
    res.setHeader('Location', `/purchases/${purchase.id}`);
    return { data: purchaseResource(purchase, documentLines), links: { self: `/purchases/${purchase.id}` } };
  }
}
