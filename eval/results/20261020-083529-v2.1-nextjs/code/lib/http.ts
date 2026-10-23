export const INT_MAX = 2147483647;

export class HttpError extends Error {
  constructor(
    public readonly status: number,
    message: string,
  ) {
    super(message);
  }
}

export function fail(status: number, message: string): Response {
  return Response.json({ error: message }, { status });
}

export async function handle(fn: () => Promise<Response>): Promise<Response> {
  try {
    return await fn();
  } catch (error) {
    if (error instanceof HttpError) {
      return fail(error.status, error.message);
    }
    console.error(error);
    return fail(500, 'Internal server error');
  }
}

/** Path ids: anything that is not a plain positive integer is simply not found. */
export function parseId(raw: string): number | null {
  if (!/^\d+$/.test(raw)) return null;
  const id = Number(raw);
  return id >= 1 && id <= INT_MAX ? id : null;
}

/** Query parameter integers: malformed values are a 400. */
export function parseInteger(raw: string, name: string): number {
  if (!/^-?\d+$/.test(raw) || !Number.isSafeInteger(Number(raw))) {
    throw new HttpError(400, `${name} must be an integer`);
  }
  return Number(raw);
}

export function parseDate(raw: string, name: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
  if (match) {
    const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    const date = new Date(Date.UTC(year, month - 1, day));
    if (
      date.getUTCFullYear() === year &&
      date.getUTCMonth() === month - 1 &&
      date.getUTCDate() === day
    ) {
      return raw;
    }
  }
  throw new HttpError(400, `${name} must be a date as YYYY-MM-DD`);
}

export function parseBoolean(raw: string, name: string): boolean {
  if (raw === 'true') return true;
  if (raw === 'false') return false;
  throw new HttpError(400, `${name} must be true or false`);
}

export function parsePaging(
  searchParams: URLSearchParams,
  defaultItemsPerPage = 10,
): {
  page: number;
  itemsPerPage: number;
} {
  const page = searchParams.has('page') ? parseInteger(searchParams.get('page')!, 'page') : 1;
  const itemsPerPage = searchParams.has('itemsPerPage')
    ? parseInteger(searchParams.get('itemsPerPage')!, 'itemsPerPage')
    : defaultItemsPerPage;
  if (page < 1) throw new HttpError(400, 'page must be at least 1');
  if (itemsPerPage < 1 || itemsPerPage > 50) {
    throw new HttpError(400, 'itemsPerPage must be between 1 and 50');
  }
  return { page, itemsPerPage };
}

export async function readJson(request: Request): Promise<unknown> {
  try {
    return await request.json();
  } catch {
    throw new HttpError(400, 'Body must be valid JSON');
  }
}

export function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function list<T>(items: T[], total: number, page: number, itemsPerPage: number) {
  const pages = Math.ceil(total / itemsPerPage);
  return Response.json({ items, total, page, itemsPerPage, pages });
}
