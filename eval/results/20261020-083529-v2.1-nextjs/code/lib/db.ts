import { Pool, types } from 'pg';

// DATE comes back as a YYYY-MM-DD string, BIGINT as a number.
types.setTypeParser(1082, (value: string) => value);
types.setTypeParser(20, (value: string) => Number(value));

const globalForDb = globalThis as unknown as { pool?: Pool };

export const pool =
  globalForDb.pool ??
  new Pool({
    connectionString:
      process.env.DATABASE_URL ?? 'postgres://eval_rw:eval_rw@postgres:5432/llmapi_eval',
    max: 10,
  });

globalForDb.pool = pool;
