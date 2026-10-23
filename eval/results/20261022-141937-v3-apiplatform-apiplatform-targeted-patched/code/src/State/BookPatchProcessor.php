<?php

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Book;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saves a PATCHed book. An unpublished book is answered with 204, with no body.
 */
final class BookPatchProcessor implements ProcessorInterface
{
    public function __construct(private readonly PersistProcessor $persistProcessor)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $book = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        if ($book instanceof Book && !$book->isPublished()) {
            return new Response(status: 204);
        }

        return $book;
    }
}
