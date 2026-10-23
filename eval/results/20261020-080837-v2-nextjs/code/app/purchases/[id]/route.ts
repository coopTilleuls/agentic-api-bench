import { pool } from '../../../lib/db';
import { requireCustomer } from '../../../lib/auth';
import { loadPurchases } from '../../../lib/purchases';
import { fail, handle, parseId } from '../../../lib/http';

export const dynamic = 'force-dynamic';

export function GET(request: Request, { params }: { params: Promise<{ id: string }> }) {
  return handle(async () => {
    const customerId = await requireCustomer(request);
    const id = parseId((await params).id);
    if (id === null) return fail(404, 'Purchase not found');
    const { rows } = await pool.query(
      'select id, created_at, total_cents from purchase where id = $1::int and customer_id = $2::int',
      [id, customerId],
    );
    if (rows.length === 0) return fail(404, 'Purchase not found');
    const [purchase] = await loadPurchases(rows);
    return Response.json(purchase);
  });
}
