<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\JsonApi\JsonApiDocument;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiQuery;
use App\JsonApi\ResourceObjects;
use App\Repository\BookRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves PATCH /books/{id}: partial update of title, priceCents and stock. Works on unpublished books too.
 *
 * @implements ProcessorInterface<mixed, Response>
 */
final class BookPatchProcessor implements ProcessorInterface
{
    private const MAX_INTEGER = 2147483647;

    public function __construct(private readonly BookRepository $books)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        /** @var Request $request */
        $request = $context['request'];

        if (JsonApiDocument::MEDIA_TYPE !== self::mediaType($request)) {
            throw JsonApiException::create(415, 'The Content-Type must be application/vnd.api+json.');
        }

        $bookId = JsonApiQuery::id((string) ($uriVariables['id'] ?? ''));
        $book = null === $bookId ? null : $this->books->find($bookId);
        if (null === $book) {
            throw JsonApiException::create(404, 'Book not found.');
        }

        $document = json_decode($request->getContent(), true);
        if (!\is_array($document) || !\is_array($document['data'] ?? null)) {
            throw JsonApiException::create(400, 'The request body must be a JSON:API document with a "data" object.');
        }

        $resource = $document['data'];
        if (isset($resource['type']) && 'books' !== $resource['type']) {
            throw JsonApiException::create(409, 'The type of the resource does not match the endpoint.', '/data/type');
        }
        if (isset($resource['id']) && (string) $resource['id'] !== (string) $bookId) {
            throw JsonApiException::create(409, 'The id of the resource does not match the id in the URL.', '/data/id');
        }

        $attributes = $resource['attributes'] ?? [];
        if (!\is_array($attributes)) {
            throw JsonApiException::create(400, 'The "attributes" member must be an object.', '/data/attributes');
        }

        $values = $this->validatedValues($attributes);

        if ([] !== $values) {
            $this->books->update($bookId, $values);
            $book = $this->books->find($bookId);
        }

        if (!$book['published']) {
            return new Response(status: 204);
        }

        return JsonApiDocument::response(JsonApiDocument::single(ResourceObjects::book($book)));
    }

    /**
     * Columns to update, from the attributes sent. Every invalid attribute is reported before anything changes.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validatedValues(array $attributes): array
    {
        $values = [];
        $errors = [];

        if (\array_key_exists('title', $attributes)) {
            $title = $attributes['title'];
            if (\is_string($title) && '' !== trim($title)) {
                $values['title'] = $title;
            } else {
                $errors[] = JsonApiException::errorObject(422, 'The title must be a non-empty string.', '/data/attributes/title');
            }
        }

        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (!\array_key_exists($attribute, $attributes)) {
                continue;
            }
            $value = $attributes[$attribute];
            if (\is_int($value) && $value >= 0 && $value <= self::MAX_INTEGER) {
                $values[$column] = $value;
            } else {
                $errors[] = JsonApiException::errorObject(422, \sprintf('The %s must be a non-negative integer.', $attribute), "/data/attributes/$attribute");
            }
        }

        if ([] !== $errors) {
            throw new JsonApiException(422, $errors);
        }

        return $values;
    }

    private static function mediaType(Request $request): string
    {
        return trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]);
    }
}
