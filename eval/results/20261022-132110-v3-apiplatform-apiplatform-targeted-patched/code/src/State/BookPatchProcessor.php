<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiException;
use App\Api\JsonApiBody;
use App\Entity\Book;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partial update of a book: only the attributes sent change. Unpublished books answer 204.
 */
final class BookPatchProcessor implements ProcessorInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Book|Response
    {
        /** @var Request $request */
        $request = $context['request'];
        $document = JsonApiBody::data($request);
        /** @var Book $book */
        $book = $data;

        if (\array_key_exists('id', $document) && (!\is_scalar($document['id']) || (string) $document['id'] !== (string) $book->getId())) {
            throw ApiException::status(409, 'The data id does not match the id of the URL.', pointer: '/data/id');
        }

        $attributes = $document['attributes'] ?? [];
        if (!\is_array($attributes)) {
            throw ApiException::status(422, 'attributes must be an object.', pointer: '/data/attributes');
        }

        $problems = [];
        if (\array_key_exists('title', $attributes) && (!\is_string($attributes['title']) || '' === trim($attributes['title']))) {
            $problems[] = ['detail' => 'title must be a non-empty string.', 'pointer' => '/data/attributes/title', 'parameter' => null];
        }
        foreach (['priceCents', 'stock'] as $name) {
            if (\array_key_exists($name, $attributes) && (!\is_int($attributes[$name]) || $attributes[$name] < 0)) {
                $problems[] = ['detail' => $name.' must be an integer of at least 0.', 'pointer' => '/data/attributes/'.$name, 'parameter' => null];
            }
        }
        if ([] !== $problems) {
            throw ApiException::problems(422, $problems);
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
        $this->entityManager->flush();

        return $book->isPublished() ? $book : new Response(status: Response::HTTP_NO_CONTENT);
    }
}
