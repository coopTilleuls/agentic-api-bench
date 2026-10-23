<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Book;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saves a PATCHed book. An unpublished book is saved too, but answers 204.
 *
 * @implements ProcessorInterface<Book, Book|Response>
 */
final class BookProcessor implements ProcessorInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Book|Response
    {
        $this->entityManager->flush();

        if (!$data->isPublished()) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return $data;
    }
}
