import { pool } from '../../../lib/db';
import { fail, handle, parseId } from '../../../lib/http';

export const dynamic = 'force-dynamic';

export function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  return handle(async () => {
    const id = parseId((await params).id);
    if (id === null) return fail(404, 'Author not found');
    const { rows } = await pool.query<{ id: number; name: string | null }>(
      'select id, name from author where id = $1::int',
      [id],
    );
    if (rows.length === 0) return fail(404, 'Author not found');
    const { rows: books } = await pool.query<{ id: number; title: string }>(
      'select id, title from book where author_id = $1::int and published order by id',
      [id],
    );
    return Response.json({ id: rows[0].id, name: rows[0].name, books });
  });
}
