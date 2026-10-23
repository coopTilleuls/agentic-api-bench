import {
  Controller,
  Get,
  Headers,
  HttpCode,
  Param,
  Post,
  Req,
  Res,
} from '@nestjs/common';
import type { Request, Response } from 'express';
import { notFound } from '../common/errors.js';
import {
  listDocument,
  pageLinks,
  requestData,
  singleDocument,
} from '../common/jsonapi.js';
import { parseId, QueryParams } from '../common/query.js';
import { parsePurchaseLines } from './purchase.input.js';
import { PURCHASE_SORT, PurchasesService } from './purchases.service.js';

@Controller('purchases')
export class PurchasesController {
  constructor(private readonly purchases: PurchasesService) {}

  @Get()
  async list(
    @Req() req: Request,
    @Headers('customer-id') customerHeader?: string,
  ) {
    const customerId = await this.purchases.authenticate(customerHeader);
    const query = new QueryParams(req.originalUrl);
    const page = query.pagination();
    const sort = query.sort(PURCHASE_SORT);
    query.include([]);
    const { purchases, total } = await this.purchases.list(
      customerId,
      page,
      sort,
    );
    return listDocument(
      pageLinks(req.path, query.search, page.number, page.size, total),
      purchases,
      total,
    );
  }

  @Get(':id')
  async one(
    @Param('id') rawId: string,
    @Req() req: Request,
    @Headers('customer-id') customerHeader?: string,
  ) {
    const customerId = await this.purchases.authenticate(customerHeader);
    const query = new QueryParams(req.originalUrl);
    query.include([]);
    const id = parseId(rawId);
    const purchase =
      id === undefined
        ? undefined
        : await this.purchases.findOne(customerId, id);
    if (!purchase) {
      throw notFound('Purchase not found');
    }
    return singleDocument(`/purchases/${purchase.id}`, purchase);
  }

  @Post()
  @HttpCode(201)
  async create(
    @Req() req: Request,
    @Res({ passthrough: true }) res: Response,
    @Headers('customer-id') customerHeader?: string,
  ) {
    const customerId = await this.purchases.authenticate(customerHeader);
    const data = requestData(req);
    const lines = parsePurchaseLines(data);
    const purchase = await this.purchases.create(customerId, lines);
    res.location(purchase.links.self);
    return singleDocument(purchase.links.self, purchase);
  }
}
