<?php

namespace App\Controller;

use App\Bookshop\Presenter;
use App\Bookshop\Store;
use App\JsonApi\Body;
use App\JsonApi\Document;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookUpdateAction
{
    public function __construct(private readonly Store $store)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        Body::requireMediaType($request);

        $bookId = QueryParams::pathId($id);
        $row = null === $bookId ? null : $this->store->findBook($bookId);
        if (null === $row) {
            throw JsonApiException::create(404, 'Not found', 'No book with this id.');
        }

        $data = Body::data($request);
        if (($data['type'] ?? null) !== 'books') {
            throw JsonApiException::create(409, 'Conflict', 'The data type must be books.', '/data/type');
        }
        if (array_key_exists('id', $data) && (!is_scalar($data['id']) || (string) $data['id'] !== (string) $bookId)) {
            throw JsonApiException::create(409, 'Conflict', 'The data id must match the id of the URL.', '/data/id');
        }

        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $changes = [];
        $errors = [];
        if (array_key_exists('title', $attributes)) {
            $title = $attributes['title'];
            if (!is_string($title) || '' === trim($title)) {
                $errors[] = self::invalid('/data/attributes/title', 'title must be a non-empty string.');
            } else {
                $changes['title'] = $title;
            }
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (!array_key_exists($attribute, $attributes)) {
                continue;
            }
            $value = $attributes[$attribute];
            if (!is_int($value) || $value < 0 || $value > 2147483647) {
                $errors[] = self::invalid('/data/attributes/'.$attribute, sprintf('%s must be an integer of at least 0.', $attribute));
            } else {
                $changes[$column] = $value;
            }
        }
        if ([] !== $errors) {
            throw new JsonApiException(422, $errors);
        }

        if ([] !== $changes) {
            $this->store->updateBook($bookId, $changes);
            $row = $this->store->findBook($bookId);
        }

        if (!$row['published']) {
            return new Response(null, 204);
        }

        return Document::response(Document::single(Presenter::book($request, $row)));
    }

    /**
     * @return array{status: string, title: string, detail: string, source: array{pointer: string}}
     */
    private static function invalid(string $pointer, string $detail): array
    {
        return ['status' => '422', 'title' => 'Invalid attribute', 'detail' => $detail, 'source' => ['pointer' => $pointer]];
    }
}
