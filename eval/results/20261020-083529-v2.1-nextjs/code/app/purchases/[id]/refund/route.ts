import { pool } from '../../../../lib/db';
import { requireCustomer } from '../../../../lib/auth';
import { loadPurchases } from '../../../../lib/purchases';
import { fail, handle, HttpError, parseId } from '../../../../lib/http';

export const dynamic = 'force-dynamic';

export function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  return handle(async () => {
    const customerId = await requireCustomer(request);
    const id = parseId((await params).id);
    if (id === null) return fail(404, 'Purchase not found');

    const client = await pool.connect();
    try {
      await client.query('begin');

      // Lock the purchase so two concurrent refunds cannot both restore the stock.
      const { rows } = await client.query<{ id: number; refunded_at: Date | null }>(
        'select id, refunded_at from purchase where id = $1::int and customer_id = $2::int for update',
        [id, customerId],
      );
      if (rows.length === 0) throw new HttpError(404, 'Purchase not found');
      if (rows[0].refunded_at !== null) throw new HttpError(409, 'Purchase already refunded');

      await client.query(
        `update book b set stock = b.stock + refunded.quantity
         from (
           select book_id, sum(quantity)::int as quantity
           from purchase_line where purchase_id = $1::int
           group by book_id
         ) refunded
         where b.id = refunded.book_id`,
        [id],
      );
      const { rows: updated } = await client.query(
        `update purchase set refunded_at = now() where id = $1::int
         returning id, created_at, total_cents, refunded_at`,
        [id],
      );
      await client.query('commit');

      const [purchase] = await loadPurchases(updated);
      return Response.json(purchase);
    } catch (error) {
      await client.query('rollback').catch(() => undefined);
      throw error;
    } finally {
      client.release();
    }
  });
}
