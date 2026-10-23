<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Http\JsonApiException;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PATCH /books/{id}: updates only the attributes sent. Answers 204 for an unpublished book.
 *
 * @implements ProcessorInterface<mixed, object|null>
 */
final class BookProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly BookshopRepository $repository,
        private readonly BookshopMapper $mapper,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        $request = $this->requestStack->getCurrentRequest() ?? throw new \LogicException('A request is required.');
        $id = (int) ($uriVariables['id'] ?? 0);
        $document = JsonApiDocument::decode($request->getContent());

        $resource = $document['data'];
        if (isset($resource['type']) && 'books' !== $resource['type']) {
            throw JsonApiException::create(409, 'The type of the resource must be "books".');
        }

        $attributes = $resource['attributes'] ?? [];
        if (!\is_array($attributes)) {
            throw JsonApiException::pointer(422, '/data/attributes', 'The attributes must be an object.');
        }

        $changes = [];
        $errors = [];
        if (\array_key_exists('title', $attributes)) {
            $title = $attributes['title'];
            if (!\is_string($title) || '' === trim($title)) {
                $errors[] = ['/data/attributes/title', 'The title must be a non-empty string.'];
            } else {
                $changes['title'] = $title;
            }
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (!\array_key_exists($attribute, $attributes)) {
                continue;
            }
            $value = $attributes[$attribute];
            if (!\is_int($value) || $value < 0) {
                $errors[] = ["/data/attributes/$attribute", "The $attribute must be an integer greater than or equal to 0."];
            } else {
                $changes[$column] = $value;
            }
        }
        if ([] !== $errors) {
            throw JsonApiException::validation($errors);
        }

        if ([] !== $changes) {
            $this->repository->updateBook($id, $changes);
        }

        $row = $this->repository->findBook($id, false);
        if (false === $row) {
            return null;
        }
        $book = $this->mapper->books([$row])[0];

        if (BookshopRepository::isPublished($row['published'])) {
            return $book;
        }

        // An unpublished book is not part of the catalogue: the update is answered without a document.
        $request->attributes->set('_api_response_status', 204);

        return null;
    }
}
