import { Module } from '@nestjs/common';
import { AuthorsController } from './authors/authors.controller.js';
import { AuthorsService } from './authors/authors.service.js';
import { BooksController } from './books/books.controller.js';
import { BooksService } from './books/books.service.js';
import { OpenApiController } from './openapi/openapi.controller.js';
import { PurchasesController } from './purchases/purchases.controller.js';
import { PurchasesService } from './purchases/purchases.service.js';
import { ReportsController } from './reports/reports.controller.js';
import { ReportsService } from './reports/reports.service.js';

@Module({
  controllers: [AuthorsController, BooksController, PurchasesController, ReportsController, OpenApiController],
  providers: [AuthorsService, BooksService, PurchasesService, ReportsService],
})
export class AppModule {}
