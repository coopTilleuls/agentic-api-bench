<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Error\JsonApiException;
use App\ApiResource\BookResource;
use App\Entity\Book;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * PATCH /books/{id}: updates title, priceCents and stock only. Answers 204 for an unpublished book.
 *
 * @implements ProcessorInterface<BookResource, BookResource|Response>
 */
final class BookProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResourceMapper $mapper,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BookResource|Response
    {
        $id = (int) $uriVariables['id'];
        $body = json_decode((string) $this->requestStack->getCurrentRequest()?->getContent(), true);
        $bodyId = $body['data']['id'] ?? null;

        if (null !== $bodyId && (string) $bodyId !== (string) $id) {
            throw JsonApiException::single(409, \sprintf('The data id "%s" does not match the id "%s" of the URL.', $bodyId, $id), '/data/id');
        }

        $book = $this->entityManager->find(Book::class, $id);

        if (null === $book) {
            throw JsonApiException::single(404, 'Not found.');
        }

        $book->setTitle($data->title);
        $book->setPriceCents($data->priceCents);
        $book->setStock($data->stock);
        $this->entityManager->flush();

        if (!$book->isPublished()) {
            return new Response(status: 204);
        }

        return $this->mapper->books([$book])[0];
    }
}
