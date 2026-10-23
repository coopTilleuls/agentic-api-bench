import { Controller, Get, Inject, Req, Res } from '@nestjs/common';
import type { Request, Response } from 'express';
import pg from 'pg';
import { PG_POOL } from '../database/database.module.js';
import { badRequest } from '../common/errors.js';
import { isIsoDate, QueryParams } from '../common/query.js';

interface SalesRow {
  title: string;
  quantity: number;
  revenue_cents: string;
}

const DAY_MS = 24 * 60 * 60 * 1000;

/** RFC 4180: quote fields holding a comma, a quote or a line break; double the quotes inside. */
function csvField(value: string | number): string {
  const text = String(value);
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

@Controller('reports')
export class ReportsController {
  constructor(@Inject(PG_POOL) private readonly pool: pg.Pool) {}

  @Get('sales')
  async sales(
    @Req() req: Request,
    @Res({ passthrough: true }) res: Response,
  ): Promise<string> {
    const query = new QueryParams(req.originalUrl);
    const from = dayParameter(query, 'from');
    const to = dayParameter(query, 'to');
    if (from > to) {
      throw badRequest('"from" must not be after "to"', 'from');
    }

    // whole UTC days, both bounds included: [from 00:00, to + 1 day 00:00)
    const start = new Date(`${from}T00:00:00Z`).toISOString();
    const end = new Date(
      new Date(`${to}T00:00:00Z`).getTime() + DAY_MS,
    ).toISOString();
    const result = await this.pool.query<SalesRow>(
      `SELECT b.title, sum(pl.quantity)::int AS quantity, sum(pl.quantity * pl.unit_price_cents)::bigint AS revenue_cents
         FROM purchase_line pl
         JOIN purchase p ON p.id = pl.purchase_id
         JOIN book b ON b.id = pl.book_id
        WHERE p.created_at >= $1::timestamptz AND p.created_at < $2::timestamptz
        GROUP BY b.id, b.title
        ORDER BY revenue_cents DESC, b.title ASC, b.id ASC`,
      [start, end],
    );

    const lines = [['title', 'quantity', 'revenueCents'].join(',')];
    for (const row of result.rows) {
      lines.push(
        [
          csvField(row.title),
          csvField(row.quantity),
          csvField(row.revenue_cents),
        ].join(','),
      );
    }
    res.type('text/csv; charset=utf-8');
    return lines.join('\r\n') + '\r\n';
  }
}

function dayParameter(query: QueryParams, name: 'from' | 'to'): string {
  const value = query.get(name);
  if (value === undefined || value === '') {
    throw badRequest(`${name} is required`, name);
  }
  if (!isIsoDate(value)) {
    throw badRequest(`${name} must be a date YYYY-MM-DD`, name);
  }
  return value;
}
