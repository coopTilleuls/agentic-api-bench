import type { Request } from 'express';
import { apiError } from './errors.js';

export const JSON_API = 'application/vnd.api+json';

export interface ResourceObject {
  type: string;
  id: string;
  attributes: Record<string, unknown>;
  relationships?: Record<string, { data: unknown }>;
  links: { self: string };
}

export interface PageLinks {
  self: string;
  first: string;
  last: string;
  prev: string | null;
  next: string | null;
}

export function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Document for a single resource, with the included resources when requested. */
export function singleDocument(
  self: string,
  data: ResourceObject,
  included?: ResourceObject[],
) {
  return {
    jsonapi: { version: '1.1' },
    links: { self },
    data,
    ...(included ? { included } : {}),
  };
}

export function listDocument(
  links: PageLinks,
  data: ResourceObject[],
  total: number,
  included?: ResourceObject[],
) {
  return {
    jsonapi: { version: '1.1' },
    links,
    data,
    ...(included ? { included } : {}),
    meta: { total },
  };
}

/** Links to every page, keeping the request's filters, sort and page size. */
export function pageLinks(
  path: string,
  search: URLSearchParams,
  page: number,
  size: number,
  total: number,
): PageLinks {
  const lastPage = Math.max(1, Math.ceil(total / size));
  const url = (number: number): string => {
    const params = new URLSearchParams(search);
    params.set('page[number]', String(number));
    params.set('page[size]', String(size));
    return `${path}?${params.toString().replace(/%5B/g, '[').replace(/%5D/g, ']')}`;
  };
  return {
    self: url(page),
    first: url(1),
    last: url(lastPage),
    prev: page > 1 ? url(page - 1) : null,
    next: page < lastPage ? url(page + 1) : null,
  };
}

/** Checks the media type of a request body and returns the JSON:API `data` member. */
export function requestData(req: Request): Record<string, unknown> {
  const contentType = req.headers['content-type'] ?? '';
  if (!/^application\/vnd\.api\+json\s*(;|$)/i.test(contentType)) {
    throw apiError(415, `Content-Type must be ${JSON_API}`);
  }
  const body: unknown = req.body;
  if (!isObject(body) || !isObject(body.data)) {
    throw apiError(
      400,
      'The request body must be a JSON:API document with a "data" object',
    );
  }
  return body.data;
}

/** Collects included resources once each, in first-seen order. */
export function uniqueResources(resources: ResourceObject[]): ResourceObject[] {
  const seen = new Map<string, ResourceObject>();
  for (const resource of resources) {
    seen.set(`${resource.type}:${resource.id}`, resource);
  }
  return [...seen.values()];
}
