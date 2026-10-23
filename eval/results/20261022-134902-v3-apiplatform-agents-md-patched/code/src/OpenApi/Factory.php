<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\OpenApi;
use App\JsonApi\Document;

/**
 * Describes every operation with its JSON:API parameters, request body and responses.
 */
final class Factory implements OpenApiFactoryInterface
{
    public function __construct(private readonly OpenApiFactoryInterface $inner)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $paths = new Paths();
        foreach ($this->pathItems() as $path => $item) {
            $paths->addPath($path, $item);
        }

        return ($this->inner)($context)->withPaths($paths)->withOpenapi('3.1.0');
    }

    /**
     * @return array<string, PathItem>
     */
    private function pathItems(): array
    {
        $bookSchema = Spec::resourceSchema('books', [
            'title' => ['type' => 'string'],
            'priceCents' => ['type' => 'integer'],
            'publishedAt' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
            'stock' => ['type' => 'integer'],
        ], [
            'author' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'id' => ['type' => 'string']]]]],
        ]);
        $authorSchema = Spec::resourceSchema('authors', [
            'name' => ['type' => 'string', 'nullable' => true],
            'publishedBooks' => ['type' => 'integer'],
        ], [
            'books' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'id' => ['type' => 'string']]]]]],
        ]);
        $purchaseSchema = Spec::resourceSchema('purchases', [
            'createdAt' => ['type' => 'string', 'format' => 'date-time'],
            'totalCents' => ['type' => 'integer'],
            'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'bookId' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'quantity' => ['type' => 'integer'],
                'unitPriceCents' => ['type' => 'integer'],
            ]]],
        ]);
        $authorIncluded = Spec::resourceSchema('authors', ['name' => ['type' => 'string', 'nullable' => true]]);

        $bookFilters = [
            Spec::query('filter[title]', 'Title contains the text, case-insensitive.', ['type' => 'string']),
            Spec::query('filter[author]', 'Author name, exact.', ['type' => 'string']),
            Spec::query('filter[minPrice]', 'Minimum price in cents, inclusive.', ['type' => 'integer']),
            Spec::query('filter[maxPrice]', 'Maximum price in cents, inclusive.', ['type' => 'integer']),
            Spec::query('filter[publishedAfter]', 'Published on or after this date (YYYY-MM-DD).', ['type' => 'string', 'format' => 'date']),
            Spec::query('filter[publishedBefore]', 'Published on or before this date (YYYY-MM-DD).', ['type' => 'string', 'format' => 'date']),
            Spec::query('filter[inStock]', 'true: stock greater than 0; false: stock 0.', ['type' => 'boolean']),
        ];
        $bookSort = Spec::query('sort', 'Comma separated fields among priceCents, title, publishedAt; prefix with - for descending. Ties are broken by id ascending.', ['type' => 'string']);
        $customerHeader = Spec::header('Customer-Id', 'Id of the customer; required, unknown or malformed ids answer 401.');
        $idParam = new \ApiPlatform\OpenApi\Model\Parameter('id', 'path', 'Resource id.', true, false, false, ['type' => 'string']);
        $bookBody = Spec::jsonRequestBody(['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'required' => ['type', 'id', 'attributes'], 'properties' => [
            'type' => ['type' => 'string', 'enum' => ['books']],
            'id' => ['type' => 'string'],
            'attributes' => ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1],
                'priceCents' => ['type' => 'integer', 'minimum' => 0],
                'stock' => ['type' => 'integer', 'minimum' => 0],
            ]],
        ]]]]);
        $purchaseBody = Spec::jsonRequestBody(['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'required' => ['type', 'attributes'], 'properties' => [
            'type' => ['type' => 'string', 'enum' => ['purchases']],
            'attributes' => ['type' => 'object', 'required' => ['lines'], 'properties' => [
                'lines' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'required' => ['bookId', 'quantity'], 'properties' => [
                    'bookId' => ['type' => 'integer'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1],
                ]]],
            ]],
        ]]]]);

        $document = static fn (array $resource, bool $collection, array $included = []): array => Spec::documentSchema($resource, $collection, $included);
        $includeAuthor = Spec::include(['author']);
        $includeBooks = Spec::include(['books']);

        $csvReport = new \ApiPlatform\OpenApi\Model\Response(
            description: 'CSV file of the books sold in the period.',
            content: new \ArrayObject(['text/csv' => new \ApiPlatform\OpenApi\Model\MediaType(new \ArrayObject(['type' => 'string']))]),
        );
        $fromTo = [
            Spec::query('from', 'First day of the period, inclusive (YYYY-MM-DD, UTC).', ['type' => 'string', 'format' => 'date'], true),
            Spec::query('to', 'Last day of the period, inclusive (YYYY-MM-DD, UTC).', ['type' => 'string', 'format' => 'date'], true),
        ];

        $paths = [
            '/authors' => (new PathItem())->withGet(Spec::operation('listAuthors', 'List authors', [...Spec::pagination(), $includeBooks], [
                '200' => Spec::jsonResponse('The authors of the current page.', $document($authorSchema, true, [$authorIncluded])),
                '400' => Spec::errorResponse('Invalid query parameter.'),
            ])),
            '/authors/{id}' => (new PathItem())->withGet(Spec::operation('getAuthor', 'Get an author with the ids of their published books', [$idParam, $includeBooks], [
                '200' => Spec::jsonResponse('The author.', $document($authorSchema, false, [$bookSchema])),
                '400' => Spec::errorResponse('Invalid include path.'),
                '404' => Spec::errorResponse('Unknown author.'),
            ])),
            '/books' => (new PathItem())->withGet(Spec::operation('listBooks', 'List published books', [...Spec::pagination(), ...$bookFilters, $bookSort, $includeAuthor], [
                '200' => Spec::jsonResponse('The books of the current page.', $document($bookSchema, true, [$authorIncluded])),
                '400' => Spec::errorResponse('Invalid query parameter (filter, sort, include or page).'),
            ])),
            '/books/{id}' => (new PathItem())
                ->withGet(Spec::operation('getBook', 'Get a published book', [$idParam, $includeAuthor], [
                    '200' => Spec::jsonResponse('The book.', $document($bookSchema, false, [$authorIncluded])),
                    '400' => Spec::errorResponse('Invalid include path.'),
                    '404' => Spec::errorResponse('Unknown or unpublished book, or malformed id.'),
                ]))
                ->withPatch(Spec::operation('updateBook', 'Partially update a book, published or not', [$idParam], [
                    '200' => Spec::jsonResponse('The updated book, when it is published.', $document($bookSchema, false)),
                    '204' => Spec::noContentResponse('The updated book is unpublished.'),
                    '404' => Spec::errorResponse('Unknown book, or malformed id.'),
                    '409' => Spec::errorResponse('The data id does not match the URL.'),
                    '415' => Spec::errorResponse('Content-Type is not application/vnd.api+json.'),
                    '422' => Spec::errorResponse('Invalid attribute; nothing is changed.'),
                ], $bookBody)),
            '/purchases' => (new PathItem())
                ->withGet(Spec::operation('listPurchases', "The customer's purchases, most recent first", [$customerHeader, ...Spec::pagination()], [
                    '200' => Spec::jsonResponse('The purchases of the current page.', $document($purchaseSchema, true)),
                    '401' => Spec::errorResponse('Missing, malformed or unknown Customer-Id.'),
                ]))
                ->withPost(Spec::operation('createPurchase', 'Buy books', [$customerHeader], [
                    '201' => Spec::jsonResponse('The stored purchase.', $document($purchaseSchema, false)),
                    '401' => Spec::errorResponse('Missing, malformed or unknown Customer-Id.'),
                    '402' => Spec::errorResponse('The payment was declined.'),
                    '409' => Spec::errorResponse('Not enough stock.'),
                    '415' => Spec::errorResponse('Content-Type is not application/vnd.api+json.'),
                    '422' => Spec::errorResponse('Invalid lines.'),
                    '502' => Spec::errorResponse('The payment service failed.'),
                ], $purchaseBody)),
            '/purchases/{id}' => (new PathItem())->withGet(Spec::operation('getPurchase', "One of the customer's purchases", [$customerHeader, $idParam], [
                '200' => Spec::jsonResponse('The purchase.', $document($purchaseSchema, false)),
                '401' => Spec::errorResponse('Missing, malformed or unknown Customer-Id.'),
                '404' => Spec::errorResponse('Unknown purchase, another customer\'s purchase, or malformed id.'),
            ])),
            '/reports/sales' => (new PathItem())->withGet(Spec::operation('salesReport', 'Books sold between two dates, as CSV', $fromTo, [
                '200' => $csvReport,
                '400' => Spec::errorResponse('from or to missing or malformed, or from after to.'),
            ])),
        ];

        return $paths;
    }
}
