<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Book;
use App\Http\ApiException;
use App\Http\JsonApiBody;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partially updates title, priceCents and stock. Answers with the book, or 204 when the book is unpublished.
 *
 * @implements ProcessorInterface<Book, Book|Response>
 */
final class BookPatchProcessor implements ProcessorInterface
{
    private const MAX_INT4 = 2147483647;

    public function __construct(
        private readonly BookshopRepository $repository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Book|Response
    {
        $document = JsonApiBody::data($this->requestStack->getCurrentRequest(), 'books');

        if (array_key_exists('id', $document) && (!is_scalar($document['id']) || (string) $document['id'] !== (string) $data->id)) {
            throw ApiException::single(409, 'The id in the document does not match the id in the URL.', ['pointer' => '/data/id']);
        }

        $attributes = $document['attributes'] ?? [];
        if (!is_array($attributes)) {
            throw ApiException::single(400, 'The attributes must be an object.', ['pointer' => '/data/attributes']);
        }

        $changes = [];
        $errors = [];

        if (array_key_exists('title', $attributes)) {
            if (!is_string($attributes['title']) || '' === trim($attributes['title'])) {
                $errors[] = ApiException::error(422, 'The title must be a non-empty string.', ['pointer' => '/data/attributes/title']);
            } else {
                $changes['title'] = $attributes['title'];
            }
        }

        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (!array_key_exists($attribute, $attributes)) {
                continue;
            }
            $value = $attributes[$attribute];
            if (!is_int($value) || $value < 0 || $value > self::MAX_INT4) {
                $errors[] = ApiException::error(422, sprintf('The %s must be an integer greater than or equal to 0.', $attribute), ['pointer' => sprintf('/data/attributes/%s', $attribute)]);
            } else {
                $changes[$column] = $value;
            }
        }

        if ([] !== $errors) {
            throw new ApiException(422, $errors);
        }

        if ([] !== $changes) {
            $this->repository->updateBook($data->id, $changes);
        }

        $row = $this->repository->findBook($data->id, includeUnpublished: true);

        if (null === $row || !BookshopRepository::bool($row['published'])) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return Book::fromRow($row);
    }
}
