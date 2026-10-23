import { Injectable, OnModuleDestroy } from '@nestjs/common';
import pg from 'pg';

// int8 (count, sum) as numbers; dates kept as YYYY-MM-DD; timestamps as ISO 8601 UTC
pg.types.setTypeParser(20, (v: string) => Number(v));
pg.types.setTypeParser(1082, (v: string) => v);
pg.types.setTypeParser(1184, (v: string) => new Date(v).toISOString());

const DSN = process.env.DATABASE_URL ?? 'postgres://eval_rw:eval_rw@postgres:5432/llmapi_eval';

@Injectable()
export class Db implements OnModuleDestroy {
  readonly pool = new pg.Pool({ connectionString: DSN, max: 10 });

  query<T extends pg.QueryResultRow = any>(text: string, values?: unknown[]) {
    return this.pool.query<T>(text, values);
  }

  onModuleDestroy() {
    return this.pool.end();
  }
}
