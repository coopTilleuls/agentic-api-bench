import { NestFactory } from '@nestjs/core';
import express from 'express';
import { AppModule } from './app.module.js';
import { ApiExceptionFilter, jsonApiErrorHandler } from './common/errors.js';

const PORT = 8089;

async function bootstrap() {
  // Nest's default body parser is replaced: only JSON:API bodies are parsed
  const app = await NestFactory.create(AppModule, { bodyParser: false });
  app.use((_req: express.Request, res: express.Response, next: express.NextFunction) => {
    res.setHeader('Content-Type', 'application/vnd.api+json');
    next();
  });
  app.use(express.json({ type: 'application/vnd.api+json', limit: '100kb' }));
  app.use(jsonApiErrorHandler);
  app.useGlobalFilters(new ApiExceptionFilter());
  await app.listen(PORT);
}
await bootstrap();
