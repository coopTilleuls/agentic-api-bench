<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\JsonApi\JsonApiDocument;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiQuery;
use App\JsonApi\ResourceObjects;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves GET /books and GET /books/{id}: published books only.
 *
 * @implements ProviderInterface<Response>
 */
final class BookProvider implements ProviderInterface
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly AuthorRepository $authors,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        /** @var Request $request */
        $request = $context['request'];

        return $operation instanceof CollectionOperationInterface
            ? $this->collection($request)
            : $this->item($request, (string) ($uriVariables['id'] ?? ''));
    }

    private function collection(Request $request): Response
    {
        $include = JsonApiQuery::include($request, ['author']);
        $sorts = JsonApiQuery::sort($request, BookRepository::SORT_COLUMNS);
        [$number, $size] = JsonApiQuery::page($request);

        $page = $this->books->findPublished($this->criteria($request), $sorts, ($number - 1) * $size, $size);

        $document = [
            'data' => array_map(static fn (array $row): array => ResourceObjects::book($row), $page['items']),
            'links' => JsonApiQuery::pageLinks($request, $number, $size, $page['total']),
            'meta' => ['totalItems' => $page['total']],
        ];
        if (\in_array('author', $include, true)) {
            $document['included'] = $this->authorResources($page['items']);
        }

        return JsonApiDocument::response($document);
    }

    private function item(Request $request, string $id): Response
    {
        $include = JsonApiQuery::include($request, ['author']);

        $bookId = JsonApiQuery::id($id);
        $book = null === $bookId ? null : $this->books->find($bookId);
        if (null === $book || !$book['published']) {
            throw JsonApiException::create(404, 'Book not found.');
        }

        $included = \in_array('author', $include, true) ? $this->authorResources([$book]) : [];

        return JsonApiDocument::response(JsonApiDocument::single(ResourceObjects::book($book), $included));
    }

    /**
     * @param list<array<string, mixed>> $books
     * @return list<array<string, mixed>>
     */
    private function authorResources(array $books): array
    {
        $authorIds = array_values(array_unique(array_map(static fn (array $book): int => (int) $book['author_id'], $books)));
        $authors = $this->authors->findByIds($authorIds);

        return array_values(array_map(static fn (array $author): array => ResourceObjects::author($author), $authors));
    }

    /**
     * @return array<string, mixed>
     */
    private function criteria(Request $request): array
    {
        $filters = JsonApiQuery::filters($request);
        $minPrice = $filters['minPrice'] ?? null;
        $maxPrice = $filters['maxPrice'] ?? null;

        return [
            'title' => $filters['title'] ?? null,
            'author' => $filters['author'] ?? null,
            'minPrice' => null === $minPrice ? null : JsonApiQuery::integer($minPrice, 'filter[minPrice]'),
            'maxPrice' => null === $maxPrice ? null : JsonApiQuery::integer($maxPrice, 'filter[maxPrice]'),
            'publishedAfter' => null === ($filters['publishedAfter'] ?? null) ? null : JsonApiQuery::date($filters['publishedAfter'], 'filter[publishedAfter]'),
            'publishedBefore' => null === ($filters['publishedBefore'] ?? null) ? null : JsonApiQuery::date($filters['publishedBefore'], 'filter[publishedBefore]'),
            'inStock' => null === ($filters['inStock'] ?? null) ? null : JsonApiQuery::boolean($filters['inStock'], 'filter[inStock]'),
        ];
    }
}
