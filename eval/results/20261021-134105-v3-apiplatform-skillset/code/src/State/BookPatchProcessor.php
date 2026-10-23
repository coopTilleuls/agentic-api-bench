<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\BookResource;
use App\Entity\Book;
use App\JsonApi\JsonApiException;
use App\JsonApi\RequestDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partial update of a book: only the attributes sent change.
 *
 * @implements ProcessorInterface<BookResource, BookResource|Response>
 */
final class BookPatchProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BookResource|Response
    {
        $book = $this->em->find(Book::class, (int) $uriVariables['id']);
        if (null === $book) {
            throw JsonApiException::error(404, 'Book not found.');
        }

        $document = RequestDocument::data($this->requestStack->getCurrentRequest());
        if ((string) ($document['id'] ?? '') !== (string) $book->getId()) {
            throw JsonApiException::error(409, 'The data id must match the id of the URL.', ['pointer' => '/data/id']);
        }

        $attributes = $document['attributes'] ?? [];
        if (!\is_array($attributes)) {
            throw JsonApiException::validation([['pointer' => '/data/attributes', 'detail' => 'The attributes must be an object.']]);
        }

        $violations = [];
        if (\array_key_exists('title', $attributes) && (!\is_string($attributes['title']) || '' === trim($attributes['title']))) {
            $violations[] = ['pointer' => '/data/attributes/title', 'detail' => 'The title must be a non-empty string.'];
        }
        foreach (['priceCents', 'stock'] as $name) {
            if (\array_key_exists($name, $attributes) && (!\is_int($attributes[$name]) || $attributes[$name] < 0)) {
                $violations[] = ['pointer' => '/data/attributes/'.$name, 'detail' => sprintf('The %s must be an integer greater than or equal to 0.', $name)];
            }
        }
        if ([] !== $violations) {
            throw JsonApiException::validation($violations);
        }

        if (\array_key_exists('title', $attributes)) {
            $book->setTitle($attributes['title']);
        }
        if (\array_key_exists('priceCents', $attributes)) {
            $book->setPriceCents($attributes['priceCents']);
        }
        if (\array_key_exists('stock', $attributes)) {
            $book->setStock($attributes['stock']);
        }
        $this->em->flush();

        if (!$book->isPublished()) {
            return new Response(status: 204);
        }

        return BookResource::fromEntity($book);
    }
}
