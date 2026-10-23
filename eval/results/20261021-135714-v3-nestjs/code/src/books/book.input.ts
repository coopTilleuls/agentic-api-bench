import { invalidFields, apiError } from '../common/errors.js';
import { isObject } from '../common/jsonapi.js';
import { BookChanges } from './books.service.js';

const MAX_INTEGER = 2_147_483_647;

/** Validates a PATCH /books/{id} document; the path id has already been parsed. */
export function parseBookPatch(
  data: Record<string, unknown>,
  pathId: number,
): BookChanges {
  if (data.type !== 'books') {
    throw apiError(409, 'The resource type must be "books"', {
      pointer: '/data/type',
    });
  }
  if (data.id === undefined) {
    throw apiError(400, 'The resource object must have an "id"', {
      pointer: '/data/id',
    });
  }
  if (String(data.id) !== String(pathId)) {
    throw apiError(
      409,
      `The id ${String(data.id)} does not match the path id ${pathId}`,
      { pointer: '/data/id' },
    );
  }
  const attributes = data.attributes ?? {};
  if (!isObject(attributes)) {
    throw apiError(400, '"attributes" must be an object', {
      pointer: '/data/attributes',
    });
  }

  const errors: { pointer: string; detail: string }[] = [];
  const changes: BookChanges = {};

  if ('title' in attributes) {
    const title = attributes.title;
    if (typeof title !== 'string' || title.trim() === '') {
      errors.push({
        pointer: '/data/attributes/title',
        detail: 'title must be a non-empty string',
      });
    } else {
      changes.title = title;
    }
  }
  if ('priceCents' in attributes) {
    const value = attributes.priceCents;
    if (!isStockOrPrice(value)) {
      errors.push({
        pointer: '/data/attributes/priceCents',
        detail: 'priceCents must be an integer of at least 0',
      });
    } else {
      changes.priceCents = value;
    }
  }
  if ('stock' in attributes) {
    const value = attributes.stock;
    if (!isStockOrPrice(value)) {
      errors.push({
        pointer: '/data/attributes/stock',
        detail: 'stock must be an integer of at least 0',
      });
    } else {
      changes.stock = value;
    }
  }
  if (errors.length > 0) {
    throw invalidFields(errors);
  }
  return changes;
}

function isStockOrPrice(value: unknown): value is number {
  return (
    typeof value === 'number' &&
    Number.isInteger(value) &&
    value >= 0 &&
    value <= MAX_INTEGER
  );
}
