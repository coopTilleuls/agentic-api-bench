import { Module } from '@nestjs/common';
import { BooksModule } from '../books/books.module.js';
import { AuthorsController } from './authors.controller.js';
import { AuthorsService } from './authors.service.js';

@Module({
  imports: [BooksModule],
  controllers: [AuthorsController],
  providers: [AuthorsService],
})
export class AuthorsModule {}
