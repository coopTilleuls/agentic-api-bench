import { ArgumentsHost, Catch, ExceptionFilter, HttpException, Logger } from '@nestjs/common';
import type { NextFunction, Request, Response } from 'express';

export interface ErrorObject {
  status: string;
  title: string;
  detail: string;
  source?: { pointer?: string; parameter?: string };
}

export class ApiError extends Error {
  constructor(
    readonly httpStatus: number,
    readonly errors: ErrorObject[],
  ) {
    super(errors.map((e) => e.detail).join('; '));
  }
}

const TITLES: Record<number, string> = {
  400: 'Bad Request',
  401: 'Unauthorized',
  402: 'Payment Required',
  404: 'Not Found',
  405: 'Method Not Allowed',
  409: 'Conflict',
  413: 'Payload Too Large',
  415: 'Unsupported Media Type',
  422: 'Unprocessable Entity',
  500: 'Internal Server Error',
  502: 'Bad Gateway',
};

export function apiError(status: number, detail: string, source?: ErrorObject['source']): ApiError {
  return new ApiError(status, [
    { status: String(status), title: TITLES[status] ?? 'Error', detail, ...(source ? { source } : {}) },
  ]);
}

export function toErrorResponse(exception: unknown): { status: number; errors: ErrorObject[] } {
  if (exception instanceof ApiError) {
    return { status: exception.httpStatus, errors: exception.errors };
  }
  if (exception instanceof HttpException) {
    const status = exception.getStatus();
    return {
      status,
      errors: [{ status: String(status), title: TITLES[status] ?? 'Error', detail: exception.message }],
    };
  }
  // body-parser errors (malformed JSON, oversized body) carry their own 4xx status
  const code = (exception as { status?: unknown })?.status;
  if (typeof code === 'number' && code >= 400 && code < 500) {
    const detail = (exception as { message?: string }).message ?? 'Invalid request';
    return { status: code, errors: [{ status: String(code), title: TITLES[code] ?? 'Error', detail }] };
  }
  return {
    status: 500,
    errors: [{ status: '500', title: TITLES[500], detail: 'Unexpected error' }],
  };
}

/** Express error middleware for errors raised before Nest routing (body parsing). */
export function jsonApiErrorHandler(err: unknown, _req: Request, res: Response, next: NextFunction): void {
  if (res.headersSent) {
    next(err);
    return;
  }
  const { status, errors } = toErrorResponse(err);
  res.status(status).json({ errors });
}

@Catch()
export class ApiExceptionFilter implements ExceptionFilter {
  private readonly logger = new Logger('ApiExceptionFilter');

  catch(exception: unknown, host: ArgumentsHost): void {
    const res = host.switchToHttp().getResponse<Response>();
    const { status, errors } = toErrorResponse(exception);
    if (status >= 500) {
      this.logger.error(exception instanceof Error ? exception.stack : String(exception));
    }
    res.status(status).json({ errors });
  }
}
