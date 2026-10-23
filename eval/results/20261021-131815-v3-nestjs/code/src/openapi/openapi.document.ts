const JSONAPI = 'application/vnd.api+json';

const ref = (name: string) => ({ $ref: `#/components/schemas/${name}` });
const pathParam = (name: string, description: string) => ({
  name,
  in: 'path',
  required: true,
  description,
  schema: { type: 'string' },
});
const queryParam = (name: string, description: string, schema: object) => ({
  name,
  in: 'query',
  required: false,
  description,
  schema,
});
const pageParams = [
  queryParam('page[number]', 'Page number, from 1.', { type: 'integer', minimum: 1, default: 1 }),
  queryParam('page[size]', 'Items per page, 1 to 50.', { type: 'integer', minimum: 1, maximum: 50, default: 10 }),
];
const customerIdHeader = {
  name: 'Customer-Id',
  in: 'header',
  required: true,
  description: 'Id of the customer making the request.',
  schema: { type: 'string' },
};
const jsonApiBody = (schema: object) => ({
  required: true,
  content: { [JSONAPI]: { schema } },
});
const response = (description: string, schemaName?: string) => ({
  description,
  content: schemaName ? { [JSONAPI]: { schema: ref(schemaName) } } : undefined,
});
const errorResponse = (description: string) => response(description, 'ErrorDocument');
const listResponse = (resource: string) => ({
  description: 'A page of resources.',
  content: {
    [JSONAPI]: {
      schema: {
        type: 'object',
        required: ['data', 'links', 'meta'],
        properties: {
          data: { type: 'array', items: ref(resource) },
          included: { type: 'array', items: { type: 'object' } },
          links: ref('PageLinks'),
          meta: { type: 'object', properties: { total: { type: 'integer' } } },
        },
      },
    },
  },
});
const bookSortEnum = [
  'priceCents', 'title', 'publishedAt', '-priceCents', '-title', '-publishedAt',
];

export const OPENAPI_DOCUMENT = {
  openapi: '3.0.3',
  info: {
    title: 'Bookshop API',
    version: '1.0.0',
    description: 'JSON:API 1.1 bookshop. Responses use application/vnd.api+json except the CSV report.',
  },
  servers: [{ url: 'http://localhost:8089' }],
  paths: {
    '/openapi.json': {
      get: {
        operationId: 'getOpenApi',
        summary: 'This OpenAPI document',
        responses: { 200: { description: 'OpenAPI document.' } },
      },
    },
    '/authors': {
      get: {
        operationId: 'listAuthors',
        summary: 'List authors',
        parameters: [
          ...pageParams,
          queryParam('include', 'Related resources to include: books.', { type: 'string', enum: ['books'] }),
        ],
        responses: {
          200: listResponse('AuthorResource'),
          400: errorResponse('Invalid query parameter.'),
        },
      },
    },
    '/authors/{id}': {
      get: {
        operationId: 'getAuthor',
        summary: 'Get an author with the list of their published books',
        parameters: [
          pathParam('id', 'Author id.'),
          queryParam('include', 'Related resources to include: books.', { type: 'string', enum: ['books'] }),
        ],
        responses: {
          200: response('The author.', 'AuthorDocument'),
          400: errorResponse('Invalid include path.'),
          404: errorResponse('Unknown or malformed author id.'),
        },
      },
    },
    '/books': {
      get: {
        operationId: 'listBooks',
        summary: 'List published books',
        parameters: [
          queryParam('filter[title]', 'Title contains this text, case-insensitive.', { type: 'string' }),
          queryParam('filter[author]', 'Author name, exact.', { type: 'string' }),
          queryParam('filter[minPrice]', 'Minimum price in cents, inclusive.', { type: 'integer' }),
          queryParam('filter[maxPrice]', 'Maximum price in cents, inclusive.', { type: 'integer' }),
          queryParam('filter[publishedAfter]', 'Published on or after this date.', { type: 'string', format: 'date' }),
          queryParam('filter[publishedBefore]', 'Published on or before this date.', { type: 'string', format: 'date' }),
          queryParam('filter[inStock]', 'true: stock greater than 0; false: stock 0.', { type: 'boolean' }),
          queryParam(
            'sort',
            'Comma separated sort fields, "-" for descending; ties broken by id ascending.',
            { type: 'string', example: '-priceCents,title', enum: bookSortEnum },
          ),
          queryParam('include', 'Related resources to include: author.', { type: 'string', enum: ['author'] }),
          ...pageParams,
        ],
        responses: {
          200: listResponse('BookResource'),
          400: errorResponse('Invalid filter, sort, include or pagination parameter.'),
        },
      },
    },
    '/books/{id}': {
      get: {
        operationId: 'getBook',
        summary: 'Get a published book',
        parameters: [
          pathParam('id', 'Book id.'),
          queryParam('include', 'Related resources to include: author.', { type: 'string', enum: ['author'] }),
        ],
        responses: {
          200: response('The book.', 'BookDocument'),
          400: errorResponse('Invalid include path.'),
          404: errorResponse('Unknown, unpublished or malformed book id.'),
        },
      },
      patch: {
        operationId: 'updateBook',
        summary: 'Partially update a book, published or not',
        parameters: [pathParam('id', 'Book id.')],
        requestBody: jsonApiBody({
          type: 'object',
          required: ['data'],
          properties: {
            data: {
              type: 'object',
              required: ['type', 'attributes'],
              properties: {
                type: { type: 'string', enum: ['books'] },
                id: { type: 'string' },
                attributes: {
                  type: 'object',
                  properties: {
                    title: { type: 'string', minLength: 1 },
                    priceCents: { type: 'integer', minimum: 0 },
                    stock: { type: 'integer', minimum: 0 },
                  },
                },
              },
            },
          },
        }),
        responses: {
          200: response('The updated book (published books).', 'BookDocument'),
          204: { description: 'Updated; the book is unpublished, so there is no document.' },
          404: errorResponse('Unknown or malformed book id.'),
          409: errorResponse('data.id or data.type does not match the request.'),
          415: errorResponse('Content-Type is not application/vnd.api+json.'),
          422: errorResponse('Invalid attribute, one error per field.'),
        },
      },
    },
    '/purchases': {
      get: {
        operationId: 'listPurchases',
        summary: "List the customer's purchases, most recent first",
        parameters: [customerIdHeader, ...pageParams],
        responses: {
          200: listResponse('PurchaseResource'),
          400: errorResponse('Invalid pagination parameter.'),
          401: errorResponse('Missing, unknown or malformed Customer-Id.'),
        },
      },
      post: {
        operationId: 'createPurchase',
        summary: 'Buy books',
        parameters: [customerIdHeader],
        requestBody: jsonApiBody({
          type: 'object',
          required: ['data'],
          properties: {
            data: {
              type: 'object',
              required: ['type', 'attributes'],
              properties: {
                type: { type: 'string', enum: ['purchases'] },
                attributes: {
                  type: 'object',
                  required: ['lines'],
                  properties: {
                    lines: {
                      type: 'array',
                      minItems: 1,
                      items: {
                        type: 'object',
                        required: ['bookId', 'quantity'],
                        properties: {
                          bookId: { type: 'integer' },
                          quantity: { type: 'integer', minimum: 1 },
                        },
                      },
                    },
                  },
                },
              },
            },
          },
        }),
        responses: {
          201: {
            description: 'Purchase stored; Location header holds its URL.',
            headers: { Location: { schema: { type: 'string' } } },
            content: { [JSONAPI]: { schema: ref('PurchaseDocument') } },
          },
          401: errorResponse('Missing, unknown or malformed Customer-Id.'),
          402: errorResponse('Payment declined.'),
          409: errorResponse('Not enough stock.'),
          415: errorResponse('Content-Type is not application/vnd.api+json.'),
          422: errorResponse('Invalid lines, or a book that is not published.'),
          502: errorResponse('Payment service unreachable or failing.'),
        },
      },
    },
    '/purchases/{id}': {
      get: {
        operationId: 'getPurchase',
        summary: "Get one of the customer's purchases",
        parameters: [customerIdHeader, pathParam('id', 'Purchase id.')],
        responses: {
          200: response('The purchase.', 'PurchaseDocument'),
          401: errorResponse('Missing, unknown or malformed Customer-Id.'),
          404: errorResponse('Unknown purchase, malformed id, or another customer\'s purchase.'),
        },
      },
    },
    '/reports/sales': {
      get: {
        operationId: 'getSalesReport',
        summary: 'Books sold between two dates, as CSV (public, all customers)',
        parameters: [
          queryParam('from', 'First day, included (UTC).', { type: 'string', format: 'date' }),
          queryParam('to', 'Last day, included (UTC).', { type: 'string', format: 'date' }),
        ],
        responses: {
          200: {
            description: 'CSV with header title,quantity,revenueCents, sorted by revenueCents desc, title, book id.',
            content: { 'text/csv': { schema: { type: 'string' } } },
          },
          400: errorResponse('from or to missing, malformed, or from after to.'),
        },
      },
    },
  },
  components: {
    schemas: {
      ErrorDocument: {
        type: 'object',
        required: ['errors'],
        properties: {
          errors: {
            type: 'array',
            items: {
              type: 'object',
              required: ['status', 'title', 'detail'],
              properties: {
                status: { type: 'string' },
                title: { type: 'string' },
                detail: { type: 'string' },
                source: {
                  type: 'object',
                  properties: { pointer: { type: 'string' }, parameter: { type: 'string' } },
                },
              },
            },
          },
        },
      },
      PageLinks: {
        type: 'object',
        properties: {
          first: { type: 'string' },
          last: { type: 'string' },
          prev: { type: 'string', nullable: true },
          next: { type: 'string', nullable: true },
        },
      },
      ResourceLinks: {
        type: 'object',
        properties: { self: { type: 'string' } },
      },
      AuthorResource: {
        type: 'object',
        required: ['type', 'id', 'attributes'],
        properties: {
          type: { type: 'string', enum: ['authors'] },
          id: { type: 'string' },
          attributes: {
            type: 'object',
            properties: {
              name: { type: 'string', nullable: true },
              publishedBooks: { type: 'integer' },
            },
          },
          relationships: {
            type: 'object',
            properties: { books: { type: 'object' } },
          },
          links: ref('ResourceLinks'),
        },
      },
      AuthorDocument: {
        type: 'object',
        properties: {
          data: ref('AuthorResource'),
          included: { type: 'array', items: { type: 'object' } },
          links: ref('ResourceLinks'),
        },
      },
      BookResource: {
        type: 'object',
        required: ['type', 'id', 'attributes'],
        properties: {
          type: { type: 'string', enum: ['books'] },
          id: { type: 'string' },
          attributes: {
            type: 'object',
            properties: {
              title: { type: 'string' },
              priceCents: { type: 'integer' },
              publishedAt: { type: 'string', format: 'date', nullable: true },
              stock: { type: 'integer' },
            },
          },
          relationships: {
            type: 'object',
            properties: {
              author: {
                type: 'object',
                properties: {
                  data: {
                    type: 'object',
                    properties: { type: { type: 'string' }, id: { type: 'string' } },
                  },
                },
              },
            },
          },
          links: ref('ResourceLinks'),
        },
      },
      BookDocument: {
        type: 'object',
        properties: {
          data: ref('BookResource'),
          included: { type: 'array', items: { type: 'object' } },
          links: ref('ResourceLinks'),
        },
      },
      PurchaseLine: {
        type: 'object',
        properties: {
          bookId: { type: 'integer' },
          title: { type: 'string' },
          quantity: { type: 'integer' },
          unitPriceCents: { type: 'integer' },
        },
      },
      PurchaseResource: {
        type: 'object',
        required: ['type', 'id', 'attributes'],
        properties: {
          type: { type: 'string', enum: ['purchases'] },
          id: { type: 'string' },
          attributes: {
            type: 'object',
            properties: {
              createdAt: { type: 'string', format: 'date-time' },
              totalCents: { type: 'integer' },
              lines: { type: 'array', items: ref('PurchaseLine') },
            },
          },
          links: ref('ResourceLinks'),
        },
      },
      PurchaseDocument: {
        type: 'object',
        properties: {
          data: ref('PurchaseResource'),
          links: ref('ResourceLinks'),
        },
      },
    },
  },
};
