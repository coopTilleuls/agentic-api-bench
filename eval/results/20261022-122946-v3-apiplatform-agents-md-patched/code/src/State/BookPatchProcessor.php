<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Book;
use App\EventListener\NoContentListener;
use App\Http\JsonApiBody;
use App\Http\JsonApiException;
use App\Service\Catalog;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Partial update of a book: only the attributes sent change. Answers 204 when the book is unpublished.
 *
 * @implements ProcessorInterface<Book, Book|null>
 */
final class BookPatchProcessor implements ProcessorInterface
{
    public function __construct(private readonly Connection $db, private readonly Catalog $catalog)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?Book
    {
        /** @var Request $request */
        $request = $context['request'];
        $document = JsonApiBody::read($request);

        $resource = $document['data'] ?? null;
        if (!\is_array($resource) || array_is_list($resource)) {
            throw JsonApiException::validation([['pointer' => '/data', 'detail' => 'The document must contain a data object.']]);
        }
        if (\array_key_exists('type', $resource) && 'books' !== $resource['type']) {
            throw JsonApiException::single(409, 'Conflict', 'The data type must be books.');
        }
        if (\array_key_exists('id', $resource) && (string) $resource['id'] !== (string) $uriVariables['id']) {
            throw JsonApiException::single(409, 'Conflict', \sprintf('The data id must be %s, the id of the path.', $uriVariables['id']));
        }

        $attributes = $resource['attributes'] ?? [];
        if (!\is_array($attributes) || array_is_list($attributes)) {
            throw JsonApiException::validation([['pointer' => '/data/attributes', 'detail' => 'The attributes must be an object.']]);
        }

        $violations = [];
        $changes = [];
        if (\array_key_exists('title', $attributes)) {
            $title = $attributes['title'];
            if (!\is_string($title) || '' === trim($title)) {
                $violations[] = ['pointer' => '/data/attributes/title', 'detail' => 'The title must be a non-empty string.'];
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
                $violations[] = ['pointer' => '/data/attributes/'.$attribute, 'detail' => \sprintf('%s must be an integer greater than or equal to 0.', $attribute)];
            } else {
                $changes[$column] = $value;
            }
        }
        if ([] !== $violations) {
            throw JsonApiException::validation($violations);
        }

        $id = (int) $uriVariables['id'];
        if ([] !== $changes) {
            $this->db->update('book', $changes, ['id' => $id]);
        }

        $book = $this->catalog->findBook($id);
        if (null === $book) {
            // Unpublished: the change is stored, but the answer carries no document.
            $request->attributes->set(NoContentListener::ATTRIBUTE, true);
        }

        return $book;
    }
}
