import { Controller, Get, Req, Res } from '@nestjs/common';
import type { Request, Response } from 'express';
import { Db } from './db.js';
import { badParam, isDate, searchParams, single } from './jsonapi.js';

interface SalesRow {
  title: string;
  quantity: number;
  revenue: number;
}

// RFC 4180: quote fields holding commas, quotes or line breaks, doubling any quote.
function csvField(value: string | number): string {
  const s = String(value);
  return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

@Controller('reports')
export class ReportsController {
  constructor(private readonly db: Db) {}

  @Get('sales')
  async sales(@Req() req: Request, @Res({ passthrough: true }) res: Response) {
    const sp = searchParams(req);
    const from = single(sp, 'from');
    const to = single(sp, 'to');
    if (from === undefined || !isDate(from)) throw badParam('from', 'from is required, format YYYY-MM-DD');
    if (to === undefined || !isDate(to)) throw badParam('to', 'to is required, format YYYY-MM-DD');
    if (from > to) throw badParam('from', 'from must not be after to');

    // Whole UTC days, both bounds included.
    const { rows } = await this.db.query<SalesRow>(
      `SELECT b.title, sum(pl.quantity)::int AS quantity, sum(pl.quantity * pl.unit_price_cents)::bigint AS revenue
         FROM purchase_line pl
         JOIN purchase p ON p.id = pl.purchase_id
         JOIN book b ON b.id = pl.book_id
        WHERE p.created_at >= ($1::date::timestamp AT TIME ZONE 'UTC')
          AND p.created_at < (($2::date + 1)::timestamp AT TIME ZONE 'UTC')
        GROUP BY b.id, b.title
        ORDER BY revenue DESC, b.title, b.id`,
      [from, to],
    );

    const lines = [
      'title,quantity,revenueCents',
      ...rows.map((r) => [csvField(r.title), csvField(r.quantity), csvField(r.revenue)].join(',')),
    ];
    res.type('text/csv');
    return `${lines.join('\r\n')}\r\n`;
  }
}
