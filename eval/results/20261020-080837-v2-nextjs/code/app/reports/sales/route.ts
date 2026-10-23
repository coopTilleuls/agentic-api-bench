import { pool } from '../../../lib/db';
import { handle, HttpError, parseDate } from '../../../lib/http';

export const dynamic = 'force-dynamic';

function csvField(value: string | number): string {
  const text = String(value);
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/** Start of the UTC day after the given YYYY-MM-DD date. */
function nextDay(date: string): string {
  const [year, month, day] = date.split('-').map(Number);
  return new Date(Date.UTC(year, month - 1, day + 1)).toISOString();
}

export function GET(request: Request) {
  return handle(async () => {
    const { searchParams } = new URL(request.url);
    const fromRaw = searchParams.get('from');
    const toRaw = searchParams.get('to');
    if (fromRaw === null || toRaw === null) {
      throw new HttpError(400, 'from and to are required');
    }
    const from = parseDate(fromRaw, 'from');
    const to = parseDate(toRaw, 'to');
    if (from > to) throw new HttpError(400, 'from must not be after to');

    const { rows } = await pool.query<{ title: string; quantity: number; revenue_cents: number }>(
      `select b.title,
              sum(pl.quantity)::bigint as quantity,
              sum(pl.quantity::bigint * pl.unit_price_cents)::bigint as revenue_cents
       from purchase_line pl
       join purchase p on p.id = pl.purchase_id
       join book b on b.id = pl.book_id
       where p.created_at >= $1::timestamptz and p.created_at < $2::timestamptz
       group by b.id, b.title
       order by revenue_cents desc, b.title asc, b.id asc`,
      [`${from}T00:00:00Z`, nextDay(to)],
    );

    const lines = ['title,quantity,revenueCents'];
    for (const row of rows) {
      lines.push([row.title, row.quantity, row.revenue_cents].map(csvField).join(','));
    }
    return new Response(`${lines.join('\n')}\n`, {
      headers: {
        'content-type': 'text/csv; charset=utf-8',
        'content-disposition': `attachment; filename="sales-${from}-${to}.csv"`,
      },
    });
  });
}
