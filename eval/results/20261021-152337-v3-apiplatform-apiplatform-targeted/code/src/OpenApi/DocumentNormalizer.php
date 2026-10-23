<?php

namespace App\OpenApi;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Completes the generated OpenAPI document with what the resources cannot
 * declare on their own: the JSON:API query parameters, the Customer-Id header
 * and the error responses of each operation.
 */
final class DocumentNormalizer implements NormalizerInterface
{
    private const JSON_API = 'application/vnd.api+json';

    public function __construct(private readonly NormalizerInterface $inner)
    {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $document = $this->inner->normalize($data, $format, $context);
        if (!\is_array($document)) {
            return $document;
        }

        $document['components']['schemas']['JsonApiErrors'] = self::errorsSchema();
        $document['components']['schemas'] = self::attributeSchemas($document['components']['schemas']);

        foreach (self::operations() as $path => $methods) {
            foreach ($methods as $method => $addition) {
                if (!isset($document['paths'][$path][$method])) {
                    continue;
                }

                $operation = $document['paths'][$path][$method];
                $operation['parameters'] = self::mergeParameters($operation['parameters'] ?? [], $addition['parameters'] ?? []);
                foreach ($addition['responses'] ?? [] as $status => $response) {
                    $operation['responses'][$status] = $response;
                }
                $document['paths'][$path][$method] = $operation;
            }
        }

        return $document;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    /**
     * @param list<array<string, mixed>> $generated
     * @param list<array<string, mixed>> $added
     *
     * @return list<array<string, mixed>>
     */
    private static function mergeParameters(array $generated, array $added): array
    {
        $names = array_map(static fn (array $parameter): string => $parameter['in'].':'.$parameter['name'], $added);
        $kept = array_values(array_filter(
            $generated,
            static fn (array $parameter): bool => !\in_array($parameter['in'].':'.$parameter['name'], $names, true),
        ));

        return array_values(array_merge($kept, $added));
    }

    /**
     * The generated schemas of the attributes that are not plain: the publication date and the purchase lines.
     *
     * @param array<string, mixed> $schemas
     *
     * @return array<string, mixed>
     */
    private static function attributeSchemas(array $schemas): array
    {
        $line = [
            'type' => 'object',
            'required' => ['bookId', 'quantity'],
            'properties' => [
                'bookId' => ['type' => 'integer'],
                'quantity' => ['type' => 'integer', 'minimum' => 1],
            ],
        ];

        if (isset($schemas['books.jsonapi']['properties']['data']['properties']['attributes']['properties'])) {
            $schemas['books.jsonapi']['properties']['data']['properties']['attributes']['properties']['publishedAt'] = ['type' => 'string', 'format' => 'date', 'nullable' => true, 'readOnly' => true];
        }
        if (isset($schemas['purchases.PurchaseInput.jsonapi']['properties']['data']['properties']['attributes']['properties'])) {
            $schemas['purchases.PurchaseInput.jsonapi']['properties']['data']['properties']['attributes']['properties']['lines'] = ['type' => 'array', 'items' => $line];
        }
        if (isset($schemas['purchases.jsonapi']['properties']['data']['properties']['attributes']['properties'])) {
            $schemas['purchases.jsonapi']['properties']['data']['properties']['attributes']['properties']['lines'] = [
                'type' => 'array',
                'readOnly' => true,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'bookId' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'quantity' => ['type' => 'integer'],
                        'unitPriceCents' => ['type' => 'integer'],
                    ],
                ],
            ];
        }

        return $schemas;
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['errors'],
            'properties' => [
                'errors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['status', 'title', 'detail'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => '422'],
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'source' => [
                                'type' => 'object',
                                'properties' => [
                                    'pointer' => ['type' => 'string', 'example' => '/data/attributes/priceCents'],
                                    'parameter' => ['type' => 'string', 'example' => 'sort'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(string $description): array
    {
        return [
            'description' => $description,
            'content' => [self::JSON_API => ['schema' => ['$ref' => '#/components/schemas/JsonApiErrors']]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function query(string $name, string $description, array $schema = ['type' => 'string']): array
    {
        return ['name' => $name, 'in' => 'query', 'description' => $description, 'required' => false, 'schema' => $schema];
    }

    /**
     * @return array<string, mixed>
     */
    private static function customerId(): array
    {
        return [
            'name' => 'Customer-Id',
            'in' => 'header',
            'description' => 'The id of the customer making the request. Missing, unknown or malformed answers 401.',
            'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pagination(): array
    {
        return [
            self::query('page[number]', 'The page to return, from 1.', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
            self::query('page[size]', 'The number of items per page, from 1 to 50.', ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]),
        ];
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, mixed>
     */
    private static function include(array $paths): array
    {
        return self::query('include', 'Comma separated relationship paths to add to "included".', ['type' => 'string', 'enum' => $paths]);
    }

    /**
     * @return array<string, array<string, array{parameters?: list<array<string, mixed>>, responses?: array<string, mixed>}>>
     */
    private static function operations(): array
    {
        $bookSorts = 'Comma separated fields: priceCents, title, publishedAt. Prefix a field with "-" to sort descending. Ties are broken by id ascending.';
        $bookFilters = [
            self::query('filter[title]', 'Books whose title contains the text, case-insensitive.'),
            self::query('filter[author]', 'Books whose author has exactly this name.'),
            self::query('filter[minPrice]', 'Books priced at least this many cents.', ['type' => 'integer']),
            self::query('filter[maxPrice]', 'Books priced at most this many cents.', ['type' => 'integer']),
            self::query('filter[publishedAfter]', 'Books published on or after this date.', ['type' => 'string', 'format' => 'date']),
            self::query('filter[publishedBefore]', 'Books published on or before this date.', ['type' => 'string', 'format' => 'date']),
            self::query('filter[inStock]', '"true" for books with stock, "false" for books with no stock.', ['type' => 'string', 'enum' => ['true', 'false']]),
        ];
        $queryErrors = self::error('Invalid query parameter: the source.parameter names it.');
        $notFound = self::error('No such resource.');
        $unauthorized = self::error('The Customer-Id header is missing, unknown or malformed.');
        $purchaseHeaders = [self::customerId()];

        return [
            '/authors' => [
                'get' => [
                    'parameters' => [...self::pagination(), self::include(['books'])],
                    'responses' => ['400' => $queryErrors],
                ],
            ],
            '/authors/{id}' => [
                'get' => [
                    'parameters' => [self::include(['books'])],
                    'responses' => ['400' => $queryErrors, '404' => $notFound],
                ],
            ],
            '/books' => [
                'get' => [
                    'parameters' => [...self::pagination(), self::include(['author']), self::query('sort', $bookSorts), ...$bookFilters],
                    'responses' => ['400' => $queryErrors],
                ],
            ],
            '/books/{id}' => [
                'get' => [
                    'parameters' => [self::include(['author'])],
                    'responses' => ['400' => $queryErrors, '404' => $notFound],
                ],
                'patch' => [
                    'responses' => [
                        '204' => ['description' => 'The book is unpublished: it is saved, nothing is returned.'],
                        '400' => self::error('The body is not a JSON:API document.'),
                        '404' => $notFound,
                        '409' => self::error('The id in the body differs from the id in the path.'),
                        '415' => self::error('The Content-Type is not application/vnd.api+json.'),
                        '422' => self::error('A field is invalid: priceCents and stock are integers of at least 0, title is not empty. Nothing is changed.'),
                    ],
                ],
            ],
            '/purchases' => [
                'get' => [
                    'parameters' => [...$purchaseHeaders, ...self::pagination(), self::include([])],
                    'responses' => ['400' => $queryErrors, '401' => $unauthorized],
                ],
                'post' => [
                    'parameters' => $purchaseHeaders,
                    'responses' => [
                        '401' => $unauthorized,
                        '402' => self::error('The payment was declined. Nothing is stored.'),
                        '409' => self::error('A book has not enough stock for the total quantity asked. Nothing is stored.'),
                        '415' => self::error('The Content-Type is not application/vnd.api+json.'),
                        '422' => self::error('The lines are empty or invalid, or a book is missing or unpublished.'),
                        '502' => self::error('The payment service is unreachable, fails, or gives an unexpected answer. Nothing is stored.'),
                    ],
                ],
            ],
            '/purchases/{id}' => [
                'get' => [
                    'parameters' => [...$purchaseHeaders, self::include([])],
                    'responses' => ['400' => $queryErrors, '401' => $unauthorized, '404' => $notFound],
                ],
            ],
            '/reports/sales' => [
                'get' => [
                    'parameters' => [
                        ['name' => 'from', 'in' => 'query', 'required' => true, 'description' => 'First day of the period, included (UTC).', 'schema' => ['type' => 'string', 'format' => 'date']],
                        ['name' => 'to', 'in' => 'query', 'required' => true, 'description' => 'Last day of the period, included (UTC).', 'schema' => ['type' => 'string', 'format' => 'date']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'CSV file: title, quantity, revenueCents, one line per book sold, by revenue then title.',
                            'content' => ['text/csv' => ['schema' => ['type' => 'string']]],
                        ],
                        '400' => self::error('from or to is missing or malformed, or from is after to.'),
                    ],
                ],
            ],
        ];
    }
}
