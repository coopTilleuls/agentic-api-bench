<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Book;
use App\JsonApi\JsonApiException;
use App\JsonApi\RequestDocument;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * @implements ProcessorInterface<Book, Book|Response>
 */
final class BookProcessor implements ProcessorInterface
{
    /** The attributes that can be changed, with their column. */
    private const COLUMNS = [
        'title' => 'title',
        'priceCents' => 'price_cents',
        'stock' => 'stock',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly BookProvider $books,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Book|Response
    {
        $request = $context['request'];
        $id = (int) $uriVariables['id'];

        if (!$data instanceof Book) {
            throw JsonApiException::single(404, 'Not Found', sprintf('Book %d does not exist.', $id));
        }

        $resource = RequestDocument::data($request, 'books');
        if (!isset($resource['id'])) {
            throw JsonApiException::single(400, 'Bad Request', 'The resource object must have an id.', '/data/id');
        }
        if ((string) $resource['id'] !== (string) $id) {
            throw JsonApiException::single(409, 'Conflict', sprintf('The id of the resource (%s) does not match the id of the URL (%d).', $resource['id'], $id), '/data/id');
        }

        $attributes = RequestDocument::attributes($resource);
        $violations = $this->violations($attributes);
        if ([] !== $violations) {
            throw JsonApiException::unprocessable($violations);
        }

        $changes = array_intersect_key($attributes, self::COLUMNS);
        if ([] !== $changes) {
            $assignments = [];
            $values = [];
            foreach ($changes as $name => $value) {
                $assignments[] = self::COLUMNS[$name].' = ?';
                $values[] = $value;
            }
            $values[] = $id;

            $this->connection->executeStatement(
                'UPDATE book SET '.implode(', ', $assignments).' WHERE id = ?',
                $values,
            );
        }

        $book = $this->books->find($id, false);
        if (null === $book) {
            throw JsonApiException::single(404, 'Not Found', sprintf('Book %d does not exist.', $id));
        }

        return $this->isPublished($id) ? $book : new Response('', 204);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<array{pointer: string, detail: string}>
     */
    private function violations(array $attributes): array
    {
        $violations = [];

        if (\array_key_exists('title', $attributes) && (!\is_string($attributes['title']) || '' === trim($attributes['title']))) {
            $violations[] = ['pointer' => '/data/attributes/title', 'detail' => 'The title must be a non-empty string.'];
        }
        foreach (['priceCents', 'stock'] as $name) {
            if (\array_key_exists($name, $attributes) && (!\is_int($attributes[$name]) || $attributes[$name] < 0)) {
                $violations[] = ['pointer' => '/data/attributes/'.$name, 'detail' => sprintf('The %s must be an integer greater than or equal to 0.', $name)];
            }
        }

        return $violations;
    }

    private function isPublished(int $id): bool
    {
        return (bool) $this->connection->fetchOne('SELECT published FROM book WHERE id = ?', [$id]);
    }
}
