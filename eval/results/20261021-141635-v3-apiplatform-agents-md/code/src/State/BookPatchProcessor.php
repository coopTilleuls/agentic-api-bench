<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Book;
use App\JsonApi\JsonApiException;
use App\JsonApi\RequestBody;
use App\Repository\BookshopRepository;
use Doctrine\DBAL\Connection;

/**
 * Partial update of a book: only the attributes sent change.
 *
 * @implements ProcessorInterface<Book, Book|null>
 */
final class BookPatchProcessor implements ProcessorInterface
{
    private const MAX_INT = 2147483647;

    public function __construct(
        private readonly Connection $db,
        private readonly BookshopRepository $repository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?Book
    {
        $request = $context['request'];
        $id = (string) $uriVariables['id'];

        $resource = RequestBody::data($request);
        if (isset($resource['type']) && 'books' !== $resource['type']) {
            throw JsonApiException::create(409, 'Conflict', 'The type must be "books".');
        }
        if (isset($resource['id']) && (string) $resource['id'] !== $id) {
            throw JsonApiException::create(409, 'Conflict', \sprintf('The id %s does not match the id %s of the URL.', json_encode($resource['id']), $id));
        }

        $attributes = RequestBody::attributes($resource);
        $errors = [];
        $update = [];

        if (\array_key_exists('title', $attributes)) {
            if (!\is_string($attributes['title']) || '' === trim($attributes['title'])) {
                $errors[] = ['/data/attributes/title', 'The title must be a non-empty string.'];
            } else {
                $update['title'] = $attributes['title'];
            }
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $name => $column) {
            if (!\array_key_exists($name, $attributes)) {
                continue;
            }
            $value = $attributes[$name];
            if (!\is_int($value) || $value < 0 || $value > self::MAX_INT) {
                $errors[] = ["/data/attributes/$name", "The $name must be an integer from 0."];
            } else {
                $update[$column] = $value;
            }
        }

        if ($errors) {
            throw new JsonApiException(422, array_map(
                static fn (array $error): array => JsonApiException::error(422, 'Unprocessable Entity', $error[1], ['pointer' => $error[0]]),
                $errors,
            ));
        }

        if ($update) {
            $columns = implode(', ', array_map(static fn (string $column): string => "$column = ?", array_keys($update)));
            $this->db->executeStatement("UPDATE book SET $columns WHERE id = ?", [...array_values($update), (int) $id]);
        }

        $row = $this->repository->book((int) $id);

        // An unpublished book has no representation: the answer is 204, without a body.
        if (!$row || !$row['published']) {
            $request->attributes->set('_api_response_status', 204);

            return null;
        }

        return Mapper::book($row);
    }
}
