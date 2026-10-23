import { pool } from '../../lib/db';
import { handle, list, parsePaging } from '../../lib/http';

export const dynamic = 'force-dynamic';

export function GET(request: Request) {
  return handle(async () => {
    const { page, itemsPerPage } = parsePaging(new URL(request.url).searchParams);
    const { rows } = await pool.query<{ id: number; name: string | null; published_books: number }>(
      `select a.id, a.name, count(b.id) filter (where b.published)::int as published_books
       from author a
       left join book b on b.author_id = a.id
       group by a.id
       order by a.id
       limit $1::int offset $2::int`,
      [itemsPerPage, (page - 1) * itemsPerPage],
    );
    const { rows: count } = await pool.query<{ n: number }>('select count(*)::int as n from author');
    return list(
      rows.map((row) => ({
        id: row.id,
        name: row.name,
        publishedBooks: row.published_books,
      })),
      count[0].n,
      page,
      itemsPerPage,
    );
  });
}
