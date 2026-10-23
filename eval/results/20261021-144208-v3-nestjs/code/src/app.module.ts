import { Module } from '@nestjs/common';
import { AuthorsController } from './authors.controller.js';
import { BooksController } from './books.controller.js';
import { Db } from './db.js';
import { OpenApiController } from './openapi.controller.js';
import { PurchasesController } from './purchases.controller.js';
import { ReportsController } from './reports.controller.js';

@Module({
  controllers: [AuthorsController, BooksController, PurchasesController, ReportsController, OpenApiController],
  providers: [Db],
})
export class AppModule {}
