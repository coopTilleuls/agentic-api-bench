import { Controller, Get, Req, Res } from '@nestjs/common';
import type { Request, Response } from 'express';
import { apiError } from '../common/errors.js';
import { isValidDate, parseDateParam, queryOf } from '../common/query.js';
import { ReportsService, SalesRow } from './reports.service.js';

const CSV_HEADER = 'title,quantity,revenueCents';

/** RFC 4180: quote fields containing comma, double quote or line break; CRLF line endings. */
function csvField(value: string | number): string {
  const text = String(value);
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

export function toCsv(rows: SalesRow[]): string {
  const lines = [CSV_HEADER, ...rows.map((r) => [r.title, r.quantity, r.revenueCents].map(csvField).join(','))];
  return lines.join('\r\n') + '\r\n';
}

function requiredDate(params: URLSearchParams, name: string): string {
  const value = parseDateParam(params, name);
  if (value === undefined) {
    throw apiError(400, `${name} is required`, { parameter: name });
  }
  return value;
}

@Controller('reports')
export class ReportsController {
  constructor(private readonly reports: ReportsService) {}

  @Get('sales')
  async sales(@Req() req: Request, @Res({ passthrough: true }) res: Response): Promise<string> {
    const params = queryOf(req);
    const from = requiredDate(params, 'from');
    const to = requiredDate(params, 'to');
    if (!isValidDate(from) || !isValidDate(to) || from > to) {
      throw apiError(400, 'from must not be after to', { parameter: 'from' });
    }
    const rows = await this.reports.sales(from, to);
    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    return toCsv(rows);
  }
}
