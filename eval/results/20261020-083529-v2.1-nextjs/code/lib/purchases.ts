import type { PoolClient } from 'pg';
import { pool } from './db';
import { HttpError } from './http';

export const PAYMENT_URL = 'http://127.0.0.1:18090/payments';

type PurchaseRow = { id: number; created_at: Date; total_cents: number; refunded_at: Date | null };
type LineRow = {
  purchase_id: number;
  book_id: number;
  title: string;
  quantity: number;
  unit_price_cents: number;
};

export function purchaseJson(purchase: PurchaseRow, lines: LineRow[]) {
  return {
    id: purchase.id,
    createdAt: purchase.created_at.toISOString(),
    totalCents: purchase.total_cents,
    refundedAt: purchase.refunded_at?.toISOString() ?? null,
    lines: lines.map((line) => ({
      bookId: line.book_id,
      title: line.title,
      quantity: line.quantity,
      unitPriceCents: line.unit_price_cents,
    })),
  };
}

/** Returns the purchases in the given order, each with its lines attached. */
export async function loadPurchases(
  rows: PurchaseRow[],
  client: Pick<PoolClient, 'query'> = pool,
) {
  if (rows.length === 0) return [];
  const { rows: lines } = await client.query<LineRow>(
    `select pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
     from purchase_line pl
     join book b on b.id = pl.book_id
     where pl.purchase_id = any($1::int[])
     order by pl.id`,
    [rows.map((row) => row.id)],
  );
  return rows.map((row) =>
    purchaseJson(
      row,
      lines.filter((line) => line.purchase_id === row.id),
    ),
  );
}

/**
 * Asks the payment service to charge the customer.
 * Resolves to 'accepted' or throws 402 when declined, 502 when the service fails.
 */
export async function charge(customerId: number, amountCents: number): Promise<void> {
  let response: Response;
  try {
    response = await fetch(PAYMENT_URL, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ customerId, amountCents }),
      signal: AbortSignal.timeout(5000),
      cache: 'no-store',
    });
  } catch {
    throw new HttpError(502, 'Payment service unreachable');
  }
  if (!response.ok) {
    throw new HttpError(502, 'Payment service failed');
  }
  let body: unknown;
  try {
    body = await response.json();
  } catch {
    throw new HttpError(502, 'Payment service returned an invalid answer');
  }
  const status = typeof body === 'object' && body !== null ? (body as { status?: unknown }).status : undefined;
  if (status === 'accepted') return;
  if (status === 'declined') throw new HttpError(402, 'Payment declined');
  throw new HttpError(502, 'Payment service returned an invalid answer');
}
