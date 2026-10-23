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
 * Serves GET /authors and GET /authors/{id}.
 *
 * @implements ProviderInterface<Response>
 */
final class AuthorProvider implements ProviderInterface
{
    public function __construct(
        private readonly AuthorRepository $authors,
        private readonly BookRepository $books,
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
        JsonApiQuery::include($request, []);
        JsonApiQuery::sort($request, []);
        [$number, $size] = JsonApiQuery::page($request);

        $page = $this->authors->findPage(($number - 1) * $size, $size);

        return JsonApiDocument::response([
            'data' => array_map(static fn (array $row): array => ResourceObjects::author($row), $page['items']),
            'links' => JsonApiQuery::pageLinks($request, $number, $size, $page['total']),
            'meta' => ['totalItems' => $page['total']],
        ]);
    }

    private function item(Request $request, string $id): Response
    {
        $include = JsonApiQuery::include($request, ['books']);

        $authorId = JsonApiQuery::id($id);
        $author = null === $authorId ? null : $this->authors->find($authorId);
        if (null === $author) {
            throw JsonApiException::create(404, 'Author not found.');
        }

        $books = $this->books->findPublishedByAuthor($authorId);
        $resource = ResourceObjects::author($author, array_map(static fn (array $book): string => (string) $book['id'], $books));

        $included = in_array('books', $include, true)
            ? array_map(static fn (array $book): array => ResourceObjects::book($book), $books)
            : [];

        return JsonApiDocument::response(JsonApiDocument::single($resource, $included));
    }
}
