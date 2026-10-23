<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Book;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class BookPatchProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Book|Response
    {
        if (($context['request']->toArray()['data']['id'] ?? null) !== (string) $uriVariables['id']) {
            throw new ConflictHttpException('The id in the document does not match the id in the path.');
        }

        $book = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        return $book->isPublished() ? $book : new Response(status: Response::HTTP_NO_CONTENT);
    }
}
