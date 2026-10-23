const JA = 'application/vnd.api+json';

const ref = (name: string) => ({ $ref: `#/components/${name}` });
const docResponse = (description: string, schema: object) => ({
  description,
  content: { [JA]: { schema } },
});
const errorResponse = (description: string) => docResponse(description, ref('schemas/ErrorDocument'));
const customerHeader = { $ref: '#/components/parameters/CustomerId' };
const pageParams = [ref('parameters/PageNumber'), ref('parameters/PageSize')];
const idPath = { name: 'id', in: 'path', required: true, schema: { type: 'string' }, description: 'Database id' };

const listDocument = (itemRef: string, extra: object = {}) => ({
  type: 'object',
  required: ['data', 'links', 'meta'],
  properties: {
    data: { type: 'array', items: ref(itemRef) },
    included: { type: 'array', items: { type: 'object' } },
    links: ref('schemas/PageLinks'),
    meta: { type: 'object', properties: { total: { type: 'integer' } } },
    ...extra,
  },
});

const singleDocument = (itemRef: string) => ({
  type: 'object',
  required: ['data'],
  properties: { data: ref(itemRef), included: { type: 'array', items: { type: 'object' } } },
});

const bookPatchBody = {
  required: true,
  content: {
    [JA]: {
      schema: {
        type: 'object',
        required: ['data'],
        properties: {
          data: {
            type: 'object',
            required: ['type', 'id'],
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
    },
  },
};

const purchaseBody = {
  required: true,
  content: {
    [JA]: {
      schema: {
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
                        bookId: { type: 'integer', minimum: 1 },
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

const customerErrors = {
  '401': errorResponse('Missing, malformed or unknown Customer-Id'),
};

export const OPENAPI = {
  openapi: '3.0.3',
  info: {
    title: 'Bookshop API',
    version: '1.0.0',
    description: 'JSON:API 1.1 bookshop. Documents are served as application/vnd.api+json.',
  },
  paths: {
    '/authors': {
      get: {
        operationId: 'listAuthors',
        summary: 'List authors',
        parameters: [...pageParams, ref('parameters/SortAuthors'), ref('parameters/IncludeAuthors'), ref('parameters/Fields')],
        responses: {
          '200': docResponse('Authors page', listDocument('schemas/AuthorResource')),
          '400': errorResponse('Invalid query parameter'),
        },
      },
    },
    '/authors/{id}': {
      get: {
        operationId: 'getAuthor',
        summary: 'Get one author with their published books',
        parameters: [idPath, ref('parameters/IncludeAuthorBooks'), ref('parameters/Fields')],
        responses: {
          '200': docResponse('Author', singleDocument('schemas/AuthorResource')),
          '400': errorResponse('Invalid query parameter'),
          '404': errorResponse('Unknown author'),
        },
      },
    },
    '/books': {
      get: {
        operationId: 'listBooks',
        summary: 'List published books',
        parameters: [
          ...pageParams,
          ref('parameters/FilterTitle'),
          ref('parameters/FilterAuthor'),
          ref('parameters/FilterMinPrice'),
          ref('parameters/FilterMaxPrice'),
          ref('parameters/FilterPublishedAfter'),
          ref('parameters/FilterPublishedBefore'),
          ref('parameters/FilterInStock'),
          ref('parameters/SortBooks'),
          ref('parameters/IncludeBooks'),
          ref('parameters/Fields'),
        ],
        responses: {
          '200': docResponse('Books page', listDocument('schemas/BookResource')),
          '400': errorResponse('Invalid query parameter'),
        },
      },
    },
    '/books/{id}': {
      get: {
        operationId: 'getBook',
        summary: 'Get one published book',
        parameters: [idPath, ref('parameters/IncludeBooks'), ref('parameters/Fields')],
        responses: {
          '200': docResponse('Book', singleDocument('schemas/BookResource')),
          '400': errorResponse('Invalid query parameter'),
          '404': errorResponse('Unknown or unpublished book'),
        },
      },
      patch: {
        operationId: 'updateBook',
        summary: 'Update title, price and/or stock of any book (published or not)',
        parameters: [idPath],
        requestBody: bookPatchBody,
        responses: {
          '200': docResponse('Updated published book', singleDocument('schemas/BookResource')),
          '204': { description: 'Updated unpublished book, no content' },
          '404': errorResponse('Unknown book'),
          '409': errorResponse('data.id or data.type does not match the path'),
          '415': errorResponse('Content-Type is not application/vnd.api+json'),
          '422': errorResponse('Invalid attribute, one error per field'),
        },
      },
    },
    '/purchases': {
      get: {
        operationId: 'listPurchases',
        summary: 'List the purchases of the calling customer, most recent first',
        parameters: [customerHeader, ...pageParams, ref('parameters/SortPurchases'), ref('parameters/Fields')],
        responses: {
          '200': docResponse('Purchases page', listDocument('schemas/PurchaseResource')),
          '400': errorResponse('Invalid query parameter'),
          ...customerErrors,
        },
      },
      post: {
        operationId: 'createPurchase',
        summary: 'Buy books; the payment service must accept the total',
        parameters: [customerHeader],
        requestBody: purchaseBody,
        responses: {
          '201': {
            description: 'Purchase created',
            headers: { Location: { schema: { type: 'string' }, description: 'URL of the purchase' } },
            content: { [JA]: { schema: ref('schemas/PurchaseDocument') } },
          },
          '402': errorResponse('Payment declined'),
          '409': errorResponse('Not enough stock'),
          '415': errorResponse('Content-Type is not application/vnd.api+json'),
          '422': errorResponse('Invalid line, book missing or unpublished'),
          '502': errorResponse('Payment service unreachable or failing'),
          ...customerErrors,
        },
      },
    },
    '/purchases/{id}': {
      get: {
        operationId: 'getPurchase',
        summary: 'Get one purchase of the calling customer',
        parameters: [customerHeader, idPath, ref('parameters/Fields')],
        responses: {
          '200': docResponse('Purchase', ref('schemas/PurchaseDocument')),
          '404': errorResponse('Unknown purchase or another customer\'s purchase'),
          ...customerErrors,
        },
      },
    },
    '/reports/sales': {
      get: {
        operationId: 'salesReport',
        summary: 'CSV of books sold between two dates (UTC, both included), all customers',
        parameters: [
          { name: 'from', in: 'query', required: true, schema: { type: 'string', format: 'date' } },
          { name: 'to', in: 'query', required: true, schema: { type: 'string', format: 'date' } },
        ],
        responses: {
          '200': {
            description: 'CSV with header title,quantity,revenueCents',
            content: { 'text/csv': { schema: { type: 'string' } } },
          },
          '400': errorResponse('from or to missing, malformed, or from after to'),
        },
      },
    },
    '/openapi.json': {
      get: {
        operationId: 'openapiDocument',
        summary: 'This document',
        responses: { '200': { description: 'OpenAPI document', content: { 'application/json': { schema: { type: 'object' } } } } },
      },
    },
  },
  components: {
    parameters: {
      CustomerId: {
        name: 'Customer-Id',
        in: 'header',
        required: true,
        schema: { type: 'string' },
        description: 'Id of the calling customer',
      },
      PageNumber: { name: 'page[number]', in: 'query', schema: { type: 'integer', minimum: 1, default: 1 } },
      PageSize: { name: 'page[size]', in: 'query', schema: { type: 'integer', minimum: 1, maximum: 50, default: 10 } },
      FilterTitle: { name: 'filter[title]', in: 'query', schema: { type: 'string' }, description: 'Title contains, case-insensitive' },
      FilterAuthor: { name: 'filter[author]', in: 'query', schema: { type: 'string' }, description: 'Author name, exact' },
      FilterMinPrice: { name: 'filter[minPrice]', in: 'query', schema: { type: 'integer' } },
      FilterMaxPrice: { name: 'filter[maxPrice]', in: 'query', schema: { type: 'integer' } },
      FilterPublishedAfter: { name: 'filter[publishedAfter]', in: 'query', schema: { type: 'string', format: 'date' } },
      FilterPublishedBefore: { name: 'filter[publishedBefore]', in: 'query', schema: { type: 'string', format: 'date' } },
      FilterInStock: { name: 'filter[inStock]', in: 'query', schema: { type: 'boolean' } },
      SortBooks: {
          name: 'sort',
          in: 'query',
          schema: { type: 'string' },
          description: 'Comma separated: priceCents, title, publishedAt; prefix - for descending',
        },
      SortAuthors: {
          name: 'sort',
          in: 'query',
          schema: { type: 'string' },
          description: 'Comma separated: name, publishedBooks; prefix - for descending',
        },
      SortPurchases: {
          name: 'sort',
          in: 'query',
          schema: { type: 'string' },
          description: 'Comma separated: createdAt, totalCents; prefix - for descending. Default -createdAt',
        },
      IncludeBooks: { name: 'include', in: 'query', schema: { type: 'string', enum: ['author'] } },
      IncludeAuthors: { name: 'include', in: 'query', schema: { type: 'string', enum: ['books'] } },
      IncludeAuthorBooks: { name: 'include', in: 'query', schema: { type: 'string', enum: ['books'] } },
      Fields: {
        name: 'fields[type]',
        in: 'query',
        schema: { type: 'string' },
        description: 'Sparse fieldsets, e.g. fields[books]=title,priceCents',
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
          self: { type: 'string' },
          first: { type: 'string' },
          last: { type: 'string' },
          prev: { type: 'string', nullable: true },
          next: { type: 'string', nullable: true },
        },
      },
      AuthorResource: {
        type: 'object',
        required: ['type', 'id', 'attributes', 'links'],
        properties: {
          type: { type: 'string', enum: ['authors'] },
          id: { type: 'string' },
          attributes: {
            type: 'object',
            properties: { name: { type: 'string', nullable: true }, publishedBooks: { type: 'integer' } },
          },
          relationships: {
            type: 'object',
            properties: {
              books: {
                type: 'object',
                properties: {
                  data: { type: 'array', items: { type: 'object', properties: { type: { type: 'string' }, id: { type: 'string' } } } },
                },
              },
            },
          },
          links: { type: 'object', properties: { self: { type: 'string' } } },
        },
      },
      BookResource: {
        type: 'object',
        required: ['type', 'id', 'attributes', 'links'],
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
                properties: { data: { type: 'object', properties: { type: { type: 'string' }, id: { type: 'string' } } } },
              },
            },
          },
          links: { type: 'object', properties: { self: { type: 'string' } } },
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
        required: ['type', 'id', 'attributes', 'links'],
        properties: {
          type: { type: 'string', enum: ['purchases'] },
          id: { type: 'string' },
          attributes: {
            type: 'object',
            properties: {
              createdAt: { type: 'string', format: 'date-time' },
              totalCents: { type: 'integer' },
              lines: { type: 'array', items: ref('schemas/PurchaseLine') },
            },
          },
          links: { type: 'object', properties: { self: { type: 'string' } } },
        },
      },
      PurchaseDocument: singleDocument('schemas/PurchaseResource'),
    },
  },
};
