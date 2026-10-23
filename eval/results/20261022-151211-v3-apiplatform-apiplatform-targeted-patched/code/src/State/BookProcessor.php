<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ResourceMapper;
use App\ApiResource\BookResource;
use App\Entity\Book;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PATCH /books/{id}: the resource was populated with the sent attributes only.
 */
final class BookProcessor implements ProcessorInterface
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ResourceMapper $mapper)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BookResource|Response
    {
        $book = $this->em->find(Book::class, (int) $data->id);
        if (null === $book) {
            throw new NotFoundHttpException('Book not found.');
        }

        $book->setTitle($data->title);
        $book->setPriceCents($data->priceCents);
        $book->setStock($data->stock);
        $this->em->flush();

        if (!$book->isPublished()) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->mapper->book($book);
    }
}
