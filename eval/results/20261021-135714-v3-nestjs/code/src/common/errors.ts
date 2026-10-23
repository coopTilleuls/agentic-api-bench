export interface ErrorSource {
  pointer?: string;
  parameter?: string;
}

export interface ErrorObject {
  status: string;
  title: string;
  detail: string;
  source?: ErrorSource;
}

const TITLES: Record<number, string> = {
  400: 'Bad Request',
  401: 'Unauthorized',
  402: 'Payment Required',
  404: 'Not Found',
  409: 'Conflict',
  413: 'Payload Too Large',
  415: 'Unsupported Media Type',
  422: 'Unprocessable Entity',
  500: 'Internal Server Error',
  502: 'Bad Gateway',
};

export class ApiException extends Error {
  constructor(
    readonly status: number,
    readonly errors: ErrorObject[],
  ) {
    super(errors.map((error) => error.detail).join('; '));
  }
}

export function errorObject(
  status: number,
  detail: string,
  source?: ErrorSource,
): ErrorObject {
  return {
    status: String(status),
    title: TITLES[status] ?? 'Error',
    detail,
    ...(source ? { source } : {}),
  };
}

export function apiError(
  status: number,
  detail: string,
  source?: ErrorSource,
): ApiException {
  return new ApiException(status, [errorObject(status, detail, source)]);
}

export function badRequest(detail: string, parameter?: string): ApiException {
  return apiError(400, detail, parameter ? { parameter } : undefined);
}

export function notFound(detail = 'Resource not found'): ApiException {
  return apiError(404, detail);
}

export function invalidFields(
  fields: { pointer: string; detail: string }[],
): ApiException {
  return new ApiException(
    422,
    fields.map((field) =>
      errorObject(422, field.detail, { pointer: field.pointer }),
    ),
  );
}
