<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Book;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saves a book update. An unpublished book is updated but answers 204, since
 * the book document is not visible to the client.
 *
 * @implements ProcessorInterface<Book, Book|Response>
 */
final class BookPatchProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Book|Response
    {
        $book = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        return $book->isPublished() ? $book : new Response(status: 204);
    }
}
