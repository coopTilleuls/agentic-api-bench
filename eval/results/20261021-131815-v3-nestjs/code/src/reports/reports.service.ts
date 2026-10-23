import { Injectable } from '@nestjs/common';
import { pool } from '../common/db.js';

export interface SalesRow {
  book_id: number;
  title: string;
  quantity: number;
  revenueCents: number;
}

@Injectable()
export class ReportsService {
  /** Books sold between two UTC days, both bounds included. */
  async sales(from: string, to: string): Promise<SalesRow[]> {
    const start = `${from}T00:00:00Z`;
    const end = new Date(new Date(`${to}T00:00:00Z`).getTime() + 86_400_000).toISOString();
    const { rows } = await pool.query<SalesRow>(
      `SELECT b.id AS book_id, b.title,
              sum(pl.quantity) AS quantity,
              sum(pl.quantity * pl.unit_price_cents) AS "revenueCents"
         FROM purchase p
         JOIN purchase_line pl ON pl.purchase_id = p.id
         JOIN book b ON b.id = pl.book_id
        WHERE p.created_at >= $1::timestamptz AND p.created_at < $2::timestamptz
        GROUP BY b.id, b.title
        ORDER BY "revenueCents" DESC, b.title COLLATE "C" ASC, b.id ASC`,
      [start, end],
    );
    return rows;
  }
}
