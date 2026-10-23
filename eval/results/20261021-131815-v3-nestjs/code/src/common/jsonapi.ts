export type Attributes = Record<string, unknown>;

export interface ResourceObject {
  type: string;
  id: string;
  attributes: Attributes;
  relationships?: Record<string, unknown>;
  links: { self: string };
}

export interface Document {
  data: ResourceObject | ResourceObject[] | null;
  included?: ResourceObject[];
  links?: Record<string, string | null>;
  meta?: Record<string, unknown>;
}

export interface BookRow {
  id: number;
  title: string;
  author_id: number;
  price_cents: number;
  published_at: string | null;
  stock: number;
  published: boolean;
}

export interface AuthorRow {
  id: number;
  name: string | null;
  published_books: number;
}

export interface PurchaseRow {
  id: number;
  created_at: Date;
  total_cents: number;
}

export interface PurchaseLineRow {
  purchase_id: number;
  book_id: number;
  title: string;
  quantity: number;
  unit_price_cents: number;
}

export function bookResource(b: BookRow): ResourceObject {
  return {
    type: 'books',
    id: String(b.id),
    attributes: {
      title: b.title,
      priceCents: b.price_cents,
      publishedAt: b.published_at,
      stock: b.stock,
    },
    relationships: { author: { data: { type: 'authors', id: String(b.author_id) } } },
    links: { self: `/books/${b.id}` },
  };
}

export function authorResource(a: AuthorRow, bookIds?: number[]): ResourceObject {
  return {
    type: 'authors',
    id: String(a.id),
    attributes: { name: a.name, publishedBooks: a.published_books },
    ...(bookIds
      ? {
          relationships: {
            books: { data: bookIds.map((id) => ({ type: 'books', id: String(id) })) },
          },
        }
      : {}),
    links: { self: `/authors/${a.id}` },
  };
}

export function purchaseResource(p: PurchaseRow, lines: PurchaseLineRow[]): ResourceObject {
  return {
    type: 'purchases',
    id: String(p.id),
    attributes: {
      createdAt: p.created_at.toISOString(),
      totalCents: p.total_cents,
      lines: lines.map((l) => ({
        bookId: l.book_id,
        title: l.title,
        quantity: l.quantity,
        unitPriceCents: l.unit_price_cents,
      })),
    },
    links: { self: `/purchases/${p.id}` },
  };
}

/** Keeps the first occurrence of each type+id, for compound documents. */
export function dedupe(resources: ResourceObject[]): ResourceObject[] {
  const seen = new Map<string, ResourceObject>();
  for (const r of resources) {
    const key = `${r.type}:${r.id}`;
    if (!seen.has(key)) seen.set(key, r);
  }
  return [...seen.values()];
}

export function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
