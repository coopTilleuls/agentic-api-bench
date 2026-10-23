<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Book;
use App\Exception\ApiException;
use App\Http\JsonApiBody;
use App\Service\BookshopRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * @implements ProcessorInterface<Book, Book|Response|null>
 */
final class BookProcessor implements ProcessorInterface
{
    /** JSON:API attribute => column */
    private const COLUMNS = [
        'title' => 'title',
        'priceCents' => 'price_cents',
        'stock' => 'stock',
    ];

    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $context['request'];
        $id = (int) $uriVariables['id'];
        $resource = JsonApiBody::resource($request, 'books');

        if (isset($resource['id']) && (string) $resource['id'] !== (string) $id) {
            throw ApiException::single(409, 'Conflict', sprintf('The id in the body does not match the id %d of the URL.', $id));
        }

        $attributes = $resource['attributes'] ?? [];
        if (!\is_array($attributes)) {
            throw ApiException::invalidBody('/data/attributes', 'The attributes must be an object.');
        }

        $violations = [];
        $changes = [];
        if (\array_key_exists('title', $attributes)) {
            if (!\is_string($attributes['title']) || '' === trim($attributes['title'])) {
                $violations[] = ['pointer' => '/data/attributes/title', 'detail' => 'The title must be a non-empty string.'];
            } else {
                $changes[self::COLUMNS['title']] = $attributes['title'];
            }
        }
        foreach (['priceCents', 'stock'] as $name) {
            if (!\array_key_exists($name, $attributes)) {
                continue;
            }
            if (!\is_int($attributes[$name]) || $attributes[$name] < 0) {
                $violations[] = ['pointer' => '/data/attributes/' . $name, 'detail' => sprintf('%s must be an integer greater than or equal to 0.', $name)];
            } else {
                $changes[self::COLUMNS[$name]] = $attributes[$name];
            }
        }
        if ([] !== $violations) {
            throw ApiException::validation($violations);
        }

        $row = $this->repository->updateBook($id, $changes);
        if (!$row['published']) {
            return new Response(null, 204);
        }

        return BookProvider::book($row);
    }
}
