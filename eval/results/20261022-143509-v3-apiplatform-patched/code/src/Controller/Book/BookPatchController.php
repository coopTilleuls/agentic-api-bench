<?php

namespace App\Controller\Book;

use App\Catalog\BookDocument;
use App\Catalog\BookRepository;
use App\Http\ApiException;
use App\Http\JsonApi;
use App\Http\PathId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookPatchController
{
    public function __construct(private readonly BookRepository $books)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!JsonApi::hasJsonApiContentType($request)) {
            return JsonApi::error(new ApiException(415, 'The Content-Type must be application/vnd.api+json.'));
        }

        $bookId = PathId::parse($id);
        if (null === $bookId) {
            throw new ApiException(404, 'Book not found.');
        }

        $data = $this->document($request);
        if (($data['type'] ?? null) !== 'books' || !\is_scalar($data['id'] ?? null) || (string) $data['id'] !== (string) $bookId) {
            return JsonApi::error(new ApiException(409, 'The data type and id must match the book in the URL.', '/data'));
        }

        $attributes = $data['attributes'] ?? [];
        if (!\is_array($attributes)) {
            return JsonApi::error(new ApiException(400, 'The attributes member must be an object.', '/data/attributes'));
        }

        $errors = [];
        $changes = [];
        if (\array_key_exists('title', $attributes)) {
            if (!\is_string($attributes['title']) || '' === trim($attributes['title'])) {
                $errors[] = new ApiException(422, 'The title must be a non-empty string.', '/data/attributes/title');
            } else {
                $changes['title'] = $attributes['title'];
            }
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $name => $column) {
            if (!\array_key_exists($name, $attributes)) {
                continue;
            }
            if (!\is_int($attributes[$name]) || $attributes[$name] < 0) {
                $errors[] = new ApiException(422, "The $name must be an integer greater than or equal to 0.", "/data/attributes/$name");
            } else {
                $changes[$column] = $attributes[$name];
            }
        }
        if ([] !== $errors) {
            return JsonApi::errors($errors, 422);
        }

        if (null === $this->books->find($bookId, false)) {
            throw new ApiException(404, 'Book not found.');
        }
        if ([] !== $changes) {
            $this->books->update($bookId, $changes);
        }

        $row = $this->books->find($bookId, false);
        if (!$row['published']) {
            return new Response(null, 204);
        }

        return JsonApi::document(['data' => BookDocument::resource($row, JsonApi::baseUrl($request))]);
    }

    /**
     * @return array<string, mixed> the data member of the request document
     */
    private function document(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'The request body is not valid JSON.');
        }
        if (!\is_array($body) || !\is_array($body['data'] ?? null)) {
            throw new ApiException(400, 'The request body must be a JSON:API document with a data member.', '/data');
        }

        return $body['data'];
    }
}
