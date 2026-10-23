import pg from 'pg';

const { Pool, types } = pg;

// DATE comes back as its YYYY-MM-DD text, int8 (counts, sums) as a number
types.setTypeParser(1082, (value: string) => value);
types.setTypeParser(20, (value: string) => Number(value));

export const pool = new Pool({
  connectionString:
    process.env.DATABASE_URL ?? 'postgres://eval_rw:eval_rw@postgres:5432/llmapi_eval',
  max: 10,
});

export const MAX_INT = 2147483647;
