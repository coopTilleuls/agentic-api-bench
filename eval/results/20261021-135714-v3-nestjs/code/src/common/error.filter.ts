import {
  ArgumentsHost,
  Catch,
  ExceptionFilter,
  HttpException,
} from '@nestjs/common';
import type { Response } from 'express';
import { ApiException, ErrorObject, errorObject } from './errors.js';
import { JSON_API } from './jsonapi.js';

/** Renders every failure as a JSON:API error document. */
@Catch()
export class ApiErrorFilter implements ExceptionFilter {
  catch(exception: unknown, host: ArgumentsHost): void {
    const response = host.switchToHttp().getResponse<Response>();
    let status = 500;
    let errors: ErrorObject[];

    if (exception instanceof ApiException) {
      status = exception.status;
      errors = exception.errors;
    } else if (exception instanceof HttpException) {
      status = exception.getStatus();
      errors = [errorObject(status, exception.message)];
    } else if (isBodyParserError(exception)) {
      status = exception.status;
      errors = [errorObject(status, 'The request body could not be parsed')];
    } else {
      console.error(exception);
      errors = [errorObject(500, 'Internal server error')];
    }

    response.status(status).type(JSON_API).json({ errors });
  }
}

function isBodyParserError(
  exception: unknown,
): exception is { status: number } {
  return (
    typeof exception === 'object' &&
    exception !== null &&
    'type' in exception &&
    typeof exception.type === 'string' &&
    'status' in exception &&
    typeof exception.status === 'number' &&
    exception.status >= 400 &&
    exception.status < 500
  );
}
