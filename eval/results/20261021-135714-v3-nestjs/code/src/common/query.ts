import { badRequest } from './errors.js';

export const DEFAULT_PAGE_SIZE = 10;
export const MAX_PAGE_SIZE = 50;

const MAX_PAGE_NUMBER = 1_000_000_000;
const MAX_INTEGER = 2_147_483_647;

export interface Pagination {
  number: number;
  size: number;
  offset: number;
}

/** Parses a path id; anything that cannot be a database id yields undefined (404). */
export function parseId(raw: string): number | undefined {
  if (!/^[1-9]\d*$/.test(raw)) return undefined;
  const id = Number(raw);
  return id <= MAX_INTEGER ? id : undefined;
}

export function isIsoDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) return false;
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(Date.UTC(year, month - 1, day));
  return (
    date.getUTCFullYear() === year &&
    date.getUTCMonth() === month - 1 &&
    date.getUTCDate() === day
  );
}

export function escapeLike(value: string): string {
  return value.replace(/[\\%_]/g, (char) => `\\${char}`);
}

/** Typed access to the query string, including the JSON:API families (page, sort, include, filter). */
export class QueryParams {
  private readonly params: URLSearchParams;

  constructor(url: string) {
    this.params = new URL(url, 'http://localhost').searchParams;
  }

  /** The parsed query string, used to build pagination links. */
  get search(): URLSearchParams {
    return this.params;
  }

  get(name: string): string | undefined {
    return this.params.get(name) ?? undefined;
  }

  pagination(): Pagination {
    const number = this.integer('page[number]', 1, MAX_PAGE_NUMBER) ?? 1;
    const size =
      this.integer('page[size]', 1, MAX_PAGE_SIZE) ?? DEFAULT_PAGE_SIZE;
    return { number, size, offset: (number - 1) * size };
  }

  /** Returns SQL order terms for the allowed sort fields, in the order requested. */
  sort(allowed: Record<string, string>): string[] {
    const raw = this.get('sort');
    if (raw === undefined) return [];
    return raw.split(',').map((item) => {
      const descending = item.startsWith('-');
      const field = descending ? item.slice(1) : item;
      if (!Object.hasOwn(allowed, field)) {
        throw badRequest(`Unknown sort field "${field}"`, 'sort');
      }
      return `${allowed[field]} ${descending ? 'DESC' : 'ASC'}`;
    });
  }

  include(allowed: string[]): string[] {
    const raw = this.get('include');
    if (raw === undefined) return [];
    return raw.split(',').map((path) => {
      if (!allowed.includes(path)) {
        throw badRequest(`Unknown include path "${path}"`, 'include');
      }
      return path;
    });
  }

  filter(name: string): string | undefined {
    return this.get(`filter[${name}]`);
  }

  integerFilter(name: string): number | undefined {
    const raw = this.filter(name);
    if (raw === undefined) return undefined;
    if (!/^-?\d+$/.test(raw)) {
      throw badRequest(`filter[${name}] must be an integer`, `filter[${name}]`);
    }
    return Number(raw);
  }

  booleanFilter(name: string): boolean | undefined {
    const raw = this.filter(name);
    if (raw === undefined) return undefined;
    if (raw !== 'true' && raw !== 'false') {
      throw badRequest(
        `filter[${name}] must be true or false`,
        `filter[${name}]`,
      );
    }
    return raw === 'true';
  }

  dateFilter(name: string): string | undefined {
    const raw = this.filter(name);
    if (raw === undefined) return undefined;
    if (!isIsoDate(raw)) {
      throw badRequest(
        `filter[${name}] must be a date YYYY-MM-DD`,
        `filter[${name}]`,
      );
    }
    return raw;
  }

  private integer(name: string, min: number, max: number): number | undefined {
    const raw = this.get(name);
    if (raw === undefined) return undefined;
    const value = /^\d+$/.test(raw) ? Number(raw) : NaN;
    if (!(value >= min && value <= max)) {
      throw badRequest(`${name} must be an integer between 1 and ${max}`, name);
    }
    return value;
  }
}
