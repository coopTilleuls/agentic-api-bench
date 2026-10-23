import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import express from 'express';
import type { NextFunction, Request, Response } from 'express';
import { STATUS_CODES } from 'node:http';
import { AppModule } from './app.module.js';
import { JSONAPI, JsonApiExceptionFilter } from './jsonapi.js';

async function bootstrap() {
  // Nest's own body parser only knows application/json; read every body as text and parse it per route.
  const app = await NestFactory.create(AppModule, { bodyParser: false });
  app.use(express.text({ type: () => true, limit: '100kb' }));
  app.use((_req: Request, res: Response, next: NextFunction) => {
    res.setHeader('Content-Type', JSONAPI);
    next();
  });
  app.use((err: any, _req: Request, res: Response, _next: NextFunction) => {
    const status: number = err.status ?? err.statusCode ?? 500;
    const detail = status < 500 ? String(err.message) : 'unexpected error';
    res
      .status(status)
      .setHeader('Content-Type', JSONAPI)
      .end(JSON.stringify({ errors: [{ status: String(status), title: STATUS_CODES[status], detail }] }));
  });
  app.useGlobalFilters(new JsonApiExceptionFilter());
  await app.listen(8089);
}
await bootstrap();
