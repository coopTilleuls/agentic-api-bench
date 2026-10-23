import type { Request } from 'express';
import { MAX_INT } from './db.js';
import { apiError } from './errors.js';

export function queryOf(req: Request): URLSearchParams {
  return new URL(req.url, 'http://localhost').searchParams;
}

export function parseId(raw: string): number | undefined {
  if (!/^[1-9]\d*$/.test(raw)) return undefined;
  const n = Number(raw);
  return n <= MAX_INT ? n : undefined;
}

export function parseIntParam(params: URLSearchParams, name: string): number | undefined {
  const raw = params.get(name);
  if (raw === null) return undefined;
  if (!/^-?\d+$/.test(raw) || Math.abs(Number(raw)) > MAX_INT) {
    throw apiError(400, `${name} must be an integer`, { parameter: name });
  }
  return Number(raw);
}

export function parseBoolParam(params: URLSearchParams, name: string): boolean | undefined {
  const raw = params.get(name);
  if (raw === null) return undefined;
  if (raw !== 'true' && raw !== 'false') {
    throw apiError(400, `${name} must be true or false`, { parameter: name });
  }
  return raw === 'true';
}

export function isValidDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const d = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === value;
}

export function parseDateParam(params: URLSearchParams, name: string): string | undefined {
  const raw = params.get(name);
  if (raw === null) return undefined;
  if (!isValidDate(raw)) {
    throw apiError(400, `${name} must be a date YYYY-MM-DD`, { parameter: name });
  }
  return raw;
}

export interface Page {
  number: number;
  size: number;
}

function parseBounded(params: URLSearchParams, name: string, min: number, max: number, fallback: number): number {
  const raw = params.get(name);
  if (raw === null) return fallback;
  const n = /^\d+$/.test(raw) ? Number(raw) : NaN;
  if (!Number.isInteger(n) || n < min || n > max) {
    throw apiError(400, `${name} must be an integer between ${min} and ${max}`, { parameter: name });
  }
  return n;
}

export function parsePage(params: URLSearchParams): Page {
  return {
    number: parseBounded(params, 'page[number]', 1, MAX_INT, 1),
    size: parseBounded(params, 'page[size]', 1, 50, 10),
  };
}

/** Pagination links keep every other query parameter of the request. */
export function pageLinks(
  path: string,
  params: URLSearchParams,
  page: Page,
  total: number,
): Record<string, string | null> {
  const lastPage = Math.max(1, Math.ceil(total / page.size));
  const url = (n: number): string => {
    const p = new URLSearchParams(params);
    p.set('page[number]', String(n));
    p.set('page[size]', String(page.size));
    // brackets of page[...] and filter[...] stay readable; they are valid in URLs
    return `${path}?${p.toString().replace(/%5B/gi, '[').replace(/%5D/gi, ']')}`;
  };
  return {
    first: url(1),
    last: url(lastPage),
    prev: page.number > 1 ? url(Math.min(page.number - 1, lastPage)) : null,
    next: page.number < lastPage ? url(page.number + 1) : null,
  };
}

/** Turns `sort=-priceCents,title` into SQL ORDER BY terms using a whitelist. */
export function parseSort(params: URLSearchParams, allowed: Record<string, string>): string[] {
  const raw = params.get('sort');
  if (raw === null) return [];
  return raw.split(',').map((token) => {
    const trimmed = token.trim();
    const desc = trimmed.startsWith('-');
    const field = desc ? trimmed.slice(1) : trimmed;
    if (!Object.hasOwn(allowed, field)) {
      throw apiError(400, `unknown sort field "${field}"`, { parameter: 'sort' });
    }
    return `${allowed[field]} ${desc ? 'DESC' : 'ASC'}`;
  });
}

export function parseInclude(params: URLSearchParams, allowed: string[]): string[] {
  const raw = params.get('include');
  if (raw === null || raw === '') return [];
  const paths = raw.split(',').map((s) => s.trim());
  for (const path of paths) {
    if (!allowed.includes(path)) {
      throw apiError(400, `unknown include path "${path}"`, { parameter: 'include' });
    }
  }
  return [...new Set(paths)];
}
