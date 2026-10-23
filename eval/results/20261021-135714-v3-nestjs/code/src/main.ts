import { NestFactory } from '@nestjs/core';
import { NestExpressApplication } from '@nestjs/platform-express';
import type { NextFunction, Request, Response } from 'express';
import { AppModule } from './app.module.js';
import { ApiErrorFilter } from './common/error.filter.js';
import { JSON_API } from './common/jsonapi.js';

const PORT = 8089;

async function bootstrap() {
  const app = await NestFactory.create<NestExpressApplication>(AppModule, {
    bodyParser: false,
  });
  app.useBodyParser('json', { type: [JSON_API, 'application/json'] });
  app.use((_request: Request, response: Response, next: NextFunction) => {
    response.setHeader('Content-Type', JSON_API);
    next();
  });
  app.useGlobalFilters(new ApiErrorFilter());
  await app.listen(PORT);
}
await bootstrap();
