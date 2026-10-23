import { pool } from './db';
import { HttpError, INT_MAX } from './http';

/** Returns the id of the customer named by the Customer-Id header, or throws 401. */
export async function requireCustomer(request: Request): Promise<number> {
  const raw = request.headers.get('customer-id')?.trim() ?? '';
  const id = /^\d+$/.test(raw) ? Number(raw) : 0;
  if (id < 1 || id > INT_MAX) {
    throw new HttpError(401, 'Unknown or missing Customer-Id');
  }
  const { rowCount } = await pool.query('select 1 from customer where id = $1::int', [id]);
  if (!rowCount) {
    throw new HttpError(401, 'Unknown or missing Customer-Id');
  }
  return id;
}
