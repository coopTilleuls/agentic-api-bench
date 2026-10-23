import { Module } from '@nestjs/common';
import { DatabaseModule } from './database/database.module.js';
import { AuthorsModule } from './authors/authors.module.js';
import { BooksModule } from './books/books.module.js';
import { PurchasesModule } from './purchases/purchases.module.js';
import { ReportsModule } from './reports/reports.module.js';
import { OpenApiModule } from './openapi/openapi.module.js';

@Module({
  imports: [
    DatabaseModule,
    BooksModule,
    AuthorsModule,
    PurchasesModule,
    ReportsModule,
    OpenApiModule,
  ],
})
export class AppModule {}
