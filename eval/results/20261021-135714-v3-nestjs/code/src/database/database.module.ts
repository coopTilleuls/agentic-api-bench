import { Global, Module, OnApplicationShutdown, Inject } from '@nestjs/common';
import pg from 'pg';

export const PG_POOL = Symbol('PG_POOL');

const DATABASE_URL =
  process.env.DATABASE_URL ??
  'postgres://eval_rw:eval_rw@postgres:5432/llmapi_eval';

@Global()
@Module({
  providers: [
    {
      provide: PG_POOL,
      useFactory: () =>
        new pg.Pool({ connectionString: DATABASE_URL, max: 10 }),
    },
  ],
  exports: [PG_POOL],
})
export class DatabaseModule implements OnApplicationShutdown {
  constructor(@Inject(PG_POOL) private readonly pool: pg.Pool) {}

  async onApplicationShutdown(): Promise<void> {
    await this.pool.end();
  }
}
