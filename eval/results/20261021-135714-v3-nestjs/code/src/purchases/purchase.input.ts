import { apiError, invalidFields } from '../common/errors.js';
import { isObject } from '../common/jsonapi.js';
import { PurchaseLineInput } from './purchases.service.js';

const MAX_INTEGER = 2_147_483_647;

/** Validates a POST /purchases document and returns its lines. */
export function parsePurchaseLines(
  data: Record<string, unknown>,
): PurchaseLineInput[] {
  if (data.type !== 'purchases') {
    throw apiError(409, 'The resource type must be "purchases"', {
      pointer: '/data/type',
    });
  }
  const attributes = data.attributes ?? {};
  if (!isObject(attributes)) {
    throw apiError(400, '"attributes" must be an object', {
      pointer: '/data/attributes',
    });
  }

  const lines = attributes.lines;
  if (!Array.isArray(lines) || lines.length === 0) {
    throw invalidFields([
      {
        pointer: '/data/attributes/lines',
        detail: 'lines must be a non-empty array',
      },
    ]);
  }

  const errors: { pointer: string; detail: string }[] = [];
  const parsed: PurchaseLineInput[] = [];
  lines.forEach((line: unknown, index: number) => {
    const pointer = `/data/attributes/lines/${index}`;
    if (!isObject(line)) {
      errors.push({ pointer, detail: 'each line must be an object' });
      return;
    }
    const { bookId, quantity } = line;
    const validBookId =
      Number.isInteger(bookId) &&
      (bookId as number) >= 1 &&
      (bookId as number) <= MAX_INTEGER;
    const validQuantity =
      Number.isSafeInteger(quantity) && (quantity as number) >= 1;
    if (!validBookId) {
      errors.push({
        pointer: `${pointer}/bookId`,
        detail: 'bookId must be an integer',
      });
    }
    if (!validQuantity) {
      errors.push({
        pointer: `${pointer}/quantity`,
        detail: 'quantity must be an integer of at least 1',
      });
    }
    if (validBookId && validQuantity) {
      parsed.push({ bookId: bookId as number, quantity: quantity as number });
    }
  });
  if (errors.length > 0) {
    throw invalidFields(errors);
  }
  return parsed;
}
