import { JSON_API } from '../common/jsonapi.js';

const errorResponse = (description: string) => ({
  description,
  content: {
    [JSON_API]: { schema: { $ref: '#/components/schemas/ErrorDocument' } },
  },
});

const jsonApiBody = (schema: string) => ({
  required: true,
  content: {
    [JSON_API]: { schema: { $ref: `#/components/schemas/${schema}` } },
  },
});

const pageParameters = [
  { $ref: '#/components/parameters/PageNumber' },
  { $ref: '#/components/parameters/PageSize' },
];

const customerHeader = { $ref: '#/components/parameters/CustomerId' };

const unauthorized = errorResponse(
  'Missing, unknown or malformed Customer-Id header',
);

function listOperation(
  summary: string,
  parameters: unknown[],
  listSchema: string,
) {
  return {
    summary,
    parameters,
    responses: {
      '200': {
        description: 'A page of resources',
        content: {
          [JSON_API]: {
            schema: { $ref: `#/components/schemas/${listSchema}` },
          },
        },
      },
      '400': errorResponse('Invalid query parameter'),
    },
  };
}

const bookSortEnum = [
  'priceCents',
  'title',
  'publishedAt',
  '-priceCents',
  '-title',
  '-publishedAt',
];

const bookFilters = [
  {
    name: 'filter[title]',
    in: 'query',
    schema: { type: 'string' },
    description: 'Title contains the text, case-insensitive',
  },
  {
    name: 'filter[author]',
    in: 'query',
    schema: { type: 'string' },
    description: 'Author name, exact',
  },
  {
    name: 'filter[minPrice]',
    in: 'query',
    schema: { type: 'integer' },
    description: 'Price in cents, lower bound included',
  },
  {
    name: 'filter[maxPrice]',
    in: 'query',
    schema: { type: 'integer' },
    description: 'Price in cents, upper bound included',
  },
  {
    name: 'filter[publishedAfter]',
    in: 'query',
    schema: { type: 'string', format: 'date' },
    description: 'Published on or after this date',
  },
  {
    name: 'filter[publishedBefore]',
    in: 'query',
    schema: { type: 'string', format: 'date' },
    description: 'Published on or before this date',
  },
  {
    name: 'filter[inStock]',
    in: 'query',
    schema: { type: 'boolean' },
    description: 'true: stock above 0; false: stock 0',
  },
];

export const OPENAPI_DOCUMENT = {
  openapi: '3.0.3',
  info: {
    title: 'Bookshop API',
    version: '1.0.0',
    description:
      'JSON:API 1.1 bookshop. Responses use application/vnd.api+json.',
  },
  paths: {
    '/authors': {
      get: listOperation(
        'List authors',
        [
          ...pageParameters,
          {
            name: 'sort',
            in: 'query',
            schema: { type: 'string', example: '-publishedBooks,name' },
            description:
              'Comma separated fields among name and publishedBooks; prefix - for descending',
          },
          {
            name: 'include',
            in: 'query',
            schema: { type: 'string', enum: ['books'] },
            description: 'Adds the published books of the authors to included',
          },
        ],
        'AuthorList',
      ),
    },
    '/authors/{id}': {
      get: {
        summary: 'Get an author',
        parameters: [
          { $ref: '#/components/parameters/Id' },
          {
            name: 'include',
            in: 'query',
            schema: { type: 'string', enum: ['books'] },
            description: 'Adds the published books to included',
          },
        ],
        responses: {
          '200': {
            description: 'The author',
            content: {
              [JSON_API]: {
                schema: { $ref: '#/components/schemas/AuthorDocument' },
              },
            },
          },
          '404': errorResponse('Unknown author'),
        },
      },
    },
    '/books': {
      get: listOperation(
        'List published books',
        [
          ...pageParameters,
          ...bookFilters,
          {
            name: 'sort',
            in: 'query',
            schema: { type: 'string' },
            description: `Comma separated fields among priceCents, title and publishedAt; prefix - for descending. Allowed values include: ${bookSortEnum.join(', ')}`,
          },
          {
            name: 'include',
            in: 'query',
            schema: { type: 'string', enum: ['author'] },
            description: 'Adds the authors to included',
          },
        ],
        'BookList',
      ),
    },
    '/books/{id}': {
      get: {
        summary: 'Get a published book',
        parameters: [
          { $ref: '#/components/parameters/Id' },
          {
            name: 'include',
            in: 'query',
            schema: { type: 'string', enum: ['author'] },
            description: 'Adds the author to included',
          },
        ],
        responses: {
          '200': {
            description: 'The book',
            content: {
              [JSON_API]: {
                schema: { $ref: '#/components/schemas/BookDocument' },
              },
            },
          },
          '400': errorResponse('Invalid include path'),
          '404': errorResponse('Unknown or unpublished book'),
        },
      },
      patch: {
        summary:
          'Update title, priceCents and stock of a book (published or not)',
        parameters: [{ $ref: '#/components/parameters/Id' }],
        requestBody: jsonApiBody('BookPatchDocument'),
        responses: {
          '200': {
            description: 'The updated published book',
            content: {
              [JSON_API]: {
                schema: { $ref: '#/components/schemas/BookDocument' },
              },
            },
          },
          '204': {
            description: 'The book was updated and is unpublished, no content',
          },
          '400': errorResponse('Malformed document'),
          '404': errorResponse('Unknown book'),
          '409': errorResponse(
            'The data id or type does not match the path or resource',
          ),
          '415': errorResponse('Content-Type is not application/vnd.api+json'),
          '422': errorResponse('Invalid attribute, one error per field'),
        },
      },
    },
    '/purchases': {
      get: {
        summary: "List the customer's purchases, most recent first",
        parameters: [
          customerHeader,
          ...pageParameters,
          {
            name: 'sort',
            in: 'query',
            schema: { type: 'string' },
            description:
              'Comma separated fields among createdAt and totalCents; prefix - for descending',
          },
        ],
        responses: {
          '200': {
            description: 'A page of purchases',
            content: {
              [JSON_API]: {
                schema: { $ref: '#/components/schemas/PurchaseList' },
              },
            },
          },
          '400': errorResponse('Invalid query parameter'),
          '401': unauthorized,
        },
      },
      post: {
        summary: 'Buy books',
        parameters: [customerHeader],
        requestBody: jsonApiBody('PurchaseCreateDocument'),
        responses: {
          '201': {
            description: 'The purchase, Location header holds its URL',
            headers: { Location: { schema: { type: 'string' } } },
            content: {
              [JSON_API]: {
                schema: { $ref: '#/components/schemas/PurchaseDocument' },
              },
            },
          },
          '401': unauthorized,
          '402': errorResponse('Payment declined'),
          '409': errorResponse('Not enough stock'),
          '415': errorResponse('Content-Type is not application/vnd.api+json'),
          '422': errorResponse(
            'Invalid lines, or a line references an unpublished or unknown book',
          ),
          '502': errorResponse('Payment service unreachable or failing'),
        },
      },
    },
    '/purchases/{id}': {
      get: {
        summary: "Get one of the customer's purchases",
        parameters: [customerHeader, { $ref: '#/components/parameters/Id' }],
        responses: {
          '200': {
            description: 'The purchase',
            content: {
              [JSON_API]: {
                schema: { $ref: '#/components/schemas/PurchaseDocument' },
              },
            },
          },
          '401': unauthorized,
          '404': errorResponse(
            "Unknown purchase, or another customer's purchase",
          ),
        },
      },
    },
    '/reports/sales': {
      get: {
        summary: 'Sales per book between two dates, as CSV',
        parameters: [
          {
            name: 'from',
            in: 'query',
            required: true,
            schema: { type: 'string', format: 'date' },
            description: 'First day, included (UTC)',
          },
          {
            name: 'to',
            in: 'query',
            required: true,
            schema: { type: 'string', format: 'date' },
            description: 'Last day, included (UTC)',
          },
        ],
        responses: {
          '200': {
            description: 'CSV with columns title, quantity, revenueCents',
            content: { 'text/csv': { schema: { type: 'string' } } },
          },
          '400': errorResponse(
            'from or to missing or malformed, or from after to',
          ),
        },
      },
    },
    '/openapi.json': {
      get: {
        summary: 'This OpenAPI document',
        responses: {
          '200': {
            description: 'OpenAPI 3.0 document',
            content: { 'application/json': { schema: { type: 'object' } } },
          },
        },
      },
    },
  },
  components: {
    parameters: {
      Id: {
        name: 'id',
        in: 'path',
        required: true,
        schema: { type: 'string' },
        description: 'Database id',
      },
      PageNumber: {
        name: 'page[number]',
        in: 'query',
        schema: { type: 'integer', minimum: 1, default: 1 },
        description: 'Page number, from 1',
      },
      PageSize: {
        name: 'page[size]',
        in: 'query',
        schema: { type: 'integer', minimum: 1, maximum: 50, default: 10 },
        description: 'Items per page, from 1 to 50',
      },
      CustomerId: {
        name: 'Customer-Id',
        in: 'header',
        required: true,
        schema: { type: 'string' },
        description: 'Id of the customer making the request',
      },
    },
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
                  properties: {
                    pointer: { type: 'string' },
                    parameter: { type: 'string' },
                  },
                },
              },
            },
          },
        },
      },
      Links: {
        type: 'object',
        properties: { self: { type: 'string' } },
      },
      PageLinks: {
        type: 'object',
        properties: {
          self: { type: 'string' },
          first: { type: 'string' },
          last: { type: 'string' },
          prev: { type: 'string', nullable: true },
          next: { type: 'string', nullable: true },
        },
      },
      BookAttributes: {
        type: 'object',
        properties: {
          title: { type: 'string' },
          priceCents: { type: 'integer' },
          publishedAt: { type: 'string', format: 'date', nullable: true },
          stock: { type: 'integer' },
        },
      },
      Book: {
        type: 'object',
        required: ['type', 'id', 'attributes', 'links'],
        properties: {
          type: { type: 'string', enum: ['books'] },
          id: { type: 'string' },
          attributes: { $ref: '#/components/schemas/BookAttributes' },
          relationships: {
            type: 'object',
            properties: {
              author: {
                type: 'object',
                properties: {
                  data: {
                    type: 'object',
                    properties: {
                      type: { type: 'string', enum: ['authors'] },
                      id: { type: 'string' },
                    },
                  },
                },
              },
            },
          },
          links: { $ref: '#/components/schemas/Links' },
        },
      },
      Author: {
        type: 'object',
        required: ['type', 'id', 'attributes', 'links'],
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
            properties: {
              books: {
                type: 'object',
                properties: {
                  data: {
                    type: 'array',
                    items: {
                      type: 'object',
                      properties: {
                        type: { type: 'string', enum: ['books'] },
                        id: { type: 'string' },
                      },
                    },
                  },
                },
              },
            },
          },
          links: { $ref: '#/components/schemas/Links' },
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
      Purchase: {
        type: 'object',
        required: ['type', 'id', 'attributes', 'links'],
        properties: {
          type: { type: 'string', enum: ['purchases'] },
          id: { type: 'string' },
          attributes: {
            type: 'object',
            properties: {
              createdAt: { type: 'string', format: 'date-time' },
              totalCents: { type: 'integer' },
              lines: {
                type: 'array',
                items: { $ref: '#/components/schemas/PurchaseLine' },
              },
            },
          },
          links: { $ref: '#/components/schemas/Links' },
        },
      },
      BookDocument: {
        type: 'object',
        required: ['data'],
        properties: {
          links: { $ref: '#/components/schemas/Links' },
          data: { $ref: '#/components/schemas/Book' },
          included: { type: 'array', items: { type: 'object' } },
        },
      },
      BookList: {
        type: 'object',
        required: ['data', 'links', 'meta'],
        properties: {
          links: { $ref: '#/components/schemas/PageLinks' },
          data: { type: 'array', items: { $ref: '#/components/schemas/Book' } },
          included: { type: 'array', items: { type: 'object' } },
          meta: { type: 'object', properties: { total: { type: 'integer' } } },
        },
      },
      AuthorDocument: {
        type: 'object',
        required: ['data'],
        properties: {
          links: { $ref: '#/components/schemas/Links' },
          data: { $ref: '#/components/schemas/Author' },
          included: { type: 'array', items: { type: 'object' } },
        },
      },
      AuthorList: {
        type: 'object',
        required: ['data', 'links', 'meta'],
        properties: {
          links: { $ref: '#/components/schemas/PageLinks' },
          data: {
            type: 'array',
            items: { $ref: '#/components/schemas/Author' },
          },
          included: { type: 'array', items: { type: 'object' } },
          meta: { type: 'object', properties: { total: { type: 'integer' } } },
        },
      },
      PurchaseDocument: {
        type: 'object',
        required: ['data'],
        properties: {
          links: { $ref: '#/components/schemas/Links' },
          data: { $ref: '#/components/schemas/Purchase' },
        },
      },
      PurchaseList: {
        type: 'object',
        required: ['data', 'links', 'meta'],
        properties: {
          links: { $ref: '#/components/schemas/PageLinks' },
          data: {
            type: 'array',
            items: { $ref: '#/components/schemas/Purchase' },
          },
          meta: { type: 'object', properties: { total: { type: 'integer' } } },
        },
      },
      BookPatchDocument: {
        type: 'object',
        required: ['data'],
        properties: {
          data: {
            type: 'object',
            required: ['type', 'id', 'attributes'],
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
      },
      PurchaseCreateDocument: {
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
      },
    },
  },
};
