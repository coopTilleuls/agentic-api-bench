import { pool } from '../../lib/db';
import { bookJson, BOOK_SELECT, bookWhere, parseBookFilters, type BookRow } from '../../lib/books';
import { handle, list, parsePaging } from '../../lib/http';

export const dynamic = 'force-dynamic';

export function GET(request: Request) {
  return handle(async () => {
    const { searchParams } = new URL(request.url);
    const { page, itemsPerPage } = parsePaging(searchParams, 20);
    const { filters, orders } = parseBookFilters(searchParams);
    const where = bookWhere(filters);

    const { rows: count } = await pool.query<{ n: number }>(
      `select count(*)::int as n from book b join author a on a.id = b.author_id where ${where.sql}`,
      where.params,
    );
    const params = [...where.params, itemsPerPage, (page - 1) * itemsPerPage];
    const { rows } = await pool.query<BookRow>(
      `${BOOK_SELECT}
       where ${where.sql}
       order by ${orders.join(', ')}
       limit $${params.length - 1}::int offset $${params.length}::int`,
      params,
    );
    return list(rows.map(bookJson), count[0].n, page, itemsPerPage);
  });
}
