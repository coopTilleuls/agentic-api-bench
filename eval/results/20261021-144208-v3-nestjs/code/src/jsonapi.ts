import { ArgumentsHost, Catch, ExceptionFilter, HttpException } from '@nestjs/common';
import type { Request, Response } from 'express';
import { STATUS_CODES } from 'node:http';

export const JSONAPI = 'application/vnd.api+json';
export const MAX_INT4 = 2147483647;
const MIN_INT4 = -2147483648;

export interface ErrorObject {
  status: string;
  title: string;
  detail: string;
  source?: { pointer?: string; parameter?: string };
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly errors: ErrorObject[],
  ) {
    super(errors.map((e) => e.detail).join('; '));
  }
}

function errorObject(status: number, detail: string, source?: ErrorObject['source']): ErrorObject {
  return { status: String(status), title: STATUS_CODES[status] ?? 'Error', detail, source };
}

export function fail(status: number, detail: string, source?: ErrorObject['source']): ApiError {
  return new ApiError(status, [errorObject(status, detail, source)]);
}

export function badParam(parameter: string, detail: string): ApiError {
  return fail(400, detail, { parameter });
}

export function invalid(problems: { pointer: string; detail: string }[]): ApiError {
  return new ApiError(
    422,
    problems.map((p) => errorObject(422, p.detail, { pointer: p.pointer })),
  );
}

@Catch()
export class JsonApiExceptionFilter implements ExceptionFilter {
  catch(exception: unknown, host: ArgumentsHost) {
    const res = host.switchToHttp().getResponse<Response>();
    let status = 500;
    let errors: ErrorObject[];
    if (exception instanceof ApiError) {
      status = exception.status;
      errors = exception.errors;
    } else if (exception instanceof HttpException) {
      status = exception.getStatus();
      errors = [errorObject(status, exception.message)];
    } else {
      console.error(exception);
      errors = [errorObject(500, 'unexpected error')];
    }
    res.status(status).setHeader('Content-Type', JSONAPI).end(JSON.stringify({ errors }));
  }
}

export function searchParams(req: Request): URLSearchParams {
  return new URL(req.originalUrl, 'http://localhost').searchParams;
}

export function single(sp: URLSearchParams, key: string): string | undefined {
  const values = sp.getAll(key);
  if (values.length > 1) throw badParam(key, `${key} may only be given once`);
  return values[0];
}

export function intFilter(sp: URLSearchParams, key: string): number | undefined {
  const v = single(sp, key);
  if (v === undefined) return undefined;
  if (!/^-?\d+$/.test(v)) throw badParam(key, `${key} must be an integer`);
  return Math.min(Math.max(Number(v), MIN_INT4), MAX_INT4);
}

export function boolFilter(sp: URLSearchParams, key: string): boolean | undefined {
  const v = single(sp, key);
  if (v === undefined) return undefined;
  if (v !== 'true' && v !== 'false') throw badParam(key, `${key} must be true or false`);
  return v === 'true';
}

export function isDate(v: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return false;
  const d = new Date(`${v}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().startsWith(v);
}

export function dateFilter(sp: URLSearchParams, key: string): string | undefined {
  const v = single(sp, key);
  if (v === undefined) return undefined;
  if (!isDate(v)) throw badParam(key, `${key} must be a date YYYY-MM-DD`);
  return v;
}

export function pageParams(sp: URLSearchParams): { number: number; size: number; offset: number } {
  const n = single(sp, 'page[number]');
  const s = single(sp, 'page[size]');
  let number = 1;
  if (n !== undefined) {
    if (!/^\d+$/.test(n) || Number(n) < 1) throw badParam('page[number]', 'page[number] must be a number from 1');
    number = Math.min(Number(n), MAX_INT4);
  }
  let size = 10;
  if (s !== undefined) {
    if (!/^\d+$/.test(s) || Number(s) < 1 || Number(s) > 50) {
      throw badParam('page[size]', 'page[size] must be between 1 and 50');
    }
    size = Number(s);
  }
  return { number, size, offset: (number - 1) * size };
}

export function includeParam(sp: URLSearchParams, allowed: string[]): string[] {
  const tokens = sp
    .getAll('include')
    .flatMap((v) => v.split(','))
    .map((t) => t.trim())
    .filter((t) => t !== '');
  for (const t of tokens) {
    if (!allowed.includes(t)) throw badParam('include', `unknown include path ${t}`);
  }
  return [...new Set(tokens)];
}

// Returns SQL ORDER BY fragments for the requested sort fields, in the order given.
export function sortParam(sp: URLSearchParams, allowed: Record<string, string>): string[] {
  const raw = sp.getAll('sort');
  if (raw.length === 0) return [];
  return raw
    .join(',')
    .split(',')
    .map((token) => {
      const m = /^(-?)([A-Za-z]+)$/.exec(token);
      if (!m || !Object.hasOwn(allowed, m[2])) throw badParam('sort', `unknown sort field ${token}`);
      return `${allowed[m[2]]} ${m[1] ? 'DESC' : 'ASC'}`;
    });
}

// Attribute and relationship names per resource type, used for sparse fieldsets.
export const SCHEMA: Record<string, { attributes: string[]; relationships: string[] }> = {
  authors: { attributes: ['name', 'publishedBooks'], relationships: ['books'] },
  books: { attributes: ['title', 'priceCents', 'publishedAt', 'stock'], relationships: ['author'] },
  purchases: { attributes: ['createdAt', 'totalCents', 'lines'], relationships: [] },
};

export type Fieldsets = Record<string, Set<string>>;

export function fieldsParam(sp: URLSearchParams): Fieldsets {
  const out: Fieldsets = {};
  for (const key of new Set(sp.keys())) {
    const m = /^fields\[([A-Za-z]+)\]$/.exec(key);
    if (!m) continue;
    const schema = Object.hasOwn(SCHEMA, m[1]) ? SCHEMA[m[1]] : undefined;
    if (!schema) throw badParam(key, `unknown resource type ${m[1]}`);
    const names = sp
      .getAll(key)
      .join(',')
      .split(',')
      .filter((n) => n !== '');
    for (const name of names) {
      if (!schema.attributes.includes(name) && !schema.relationships.includes(name)) {
        throw badParam(key, `unknown field ${name}`);
      }
    }
    out[m[1]] = new Set(names);
  }
  return out;
}

export interface Resource {
  type: string;
  id: string;
  attributes: Record<string, unknown>;
  relationships?: Record<string, unknown>;
  links: { self: string };
}

export function project(r: Resource, fields: Fieldsets): Resource {
  const set = fields[r.type];
  if (!set) return r;
  const out: Resource = {
    type: r.type,
    id: r.id,
    attributes: Object.fromEntries(Object.entries(r.attributes).filter(([k]) => set.has(k))),
    links: r.links,
  };
  if (r.relationships) {
    out.relationships = Object.fromEntries(Object.entries(r.relationships).filter(([k]) => set.has(k)));
  }
  return out;
}

// Removes duplicates (by type and id) from the included resources.
export function included(resources: Resource[], fields: Fieldsets): Resource[] {
  const seen = new Map<string, Resource>();
  for (const r of resources) {
    const key = `${r.type}:${r.id}`;
    if (!seen.has(key)) seen.set(key, project(r, fields));
  }
  return [...seen.values()];
}

export function listLinks(base: string, sp: URLSearchParams, page: { number: number; size: number }, total: number) {
  const last = Math.max(1, Math.ceil(total / page.size));
  const link = (n: number) => {
    const q = new URLSearchParams(sp);
    q.set('page[number]', String(n));
    q.set('page[size]', String(page.size));
    // Keys stay readable (page[size]); only values are encoded.
    const query = [...q].map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
    return `${base}?${query}`;
  };
  return {
    self: link(page.number),
    first: link(1),
    last: link(last),
    prev: page.number > 1 ? link(Math.min(page.number - 1, last)) : null,
    next: page.number < last ? link(page.number + 1) : null,
  };
}

export function listDocument(
  base: string,
  sp: URLSearchParams,
  page: { number: number; size: number },
  total: number,
  data: Resource[],
  fields: Fieldsets,
  inc?: Resource[],
) {
  return {
    jsonapi: { version: '1.1' },
    data: data.map((r) => project(r, fields)),
    ...(inc ? { included: inc } : {}),
    links: listLinks(base, sp, page, total),
    meta: { total },
  };
}

export function singleDocument(data: Resource, fields: Fieldsets, inc?: Resource[]) {
  return {
    jsonapi: { version: '1.1' },
    data: project(data, fields),
    ...(inc ? { included: inc } : {}),
  };
}

export function isObject(v: unknown): v is Record<string, any> {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

// Write requests must declare the JSON:API media type, anything else answers 415.
export function requireJsonApi(req: Request): void {
  const type = String(req.headers['content-type'] ?? '')
    .split(';')[0]
    .trim()
    .toLowerCase();
  if (type !== JSONAPI) throw fail(415, `Content-Type must be ${JSONAPI}`);
}

export function jsonBody(req: Request): any {
  const raw = typeof req.body === 'string' ? req.body : '';
  try {
    return JSON.parse(raw);
  } catch {
    throw fail(400, 'request body is not valid JSON');
  }
}

export function notFound(detail = 'resource not found'): ApiError {
  return fail(404, detail);
}
