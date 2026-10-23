<?php

namespace App\Controller\Api;

use App\Bookshop\Catalog;
use App\Bookshop\Presenter;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookUpdateController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $bookId = JsonApi::pathId($id);
        if (null === $this->catalog->book($bookId)) {
            throw JsonApiException::notFound();
        }

        $data = JsonApi::requestData($request);

        if ('books' !== ($data['type'] ?? null)) {
            throw JsonApiException::conflict('The resource type must be "books".', '/data/type');
        }
        if (!\array_key_exists('id', $data) || !\is_scalar($data['id'])) {
            throw JsonApiException::validation(['/data/id' => 'The id of the book is required.']);
        }
        if ((string) $data['id'] !== (string) $bookId) {
            throw JsonApiException::conflict('The id in the body does not match the id in the path.', '/data/id');
        }

        $attributes = $data['attributes'] ?? [];
        if (!\is_array($attributes) || array_is_list($attributes)) {
            throw JsonApiException::validation(['/data/attributes' => 'The attributes must be an object.']);
        }

        $errors = [];
        $title = null;
        $priceCents = null;
        $stock = null;

        if (\array_key_exists('title', $attributes)) {
            if (!\is_string($attributes['title']) || '' === trim($attributes['title'])) {
                $errors['/data/attributes/title'] = 'The title must be a non-empty string.';
            } else {
                $title = $attributes['title'];
            }
        }
        if (\array_key_exists('priceCents', $attributes)) {
            if (!self::isNonNegativeInteger($attributes['priceCents'])) {
                $errors['/data/attributes/priceCents'] = 'The price must be a non-negative integer, in cents.';
            } else {
                $priceCents = $attributes['priceCents'];
            }
        }
        if (\array_key_exists('stock', $attributes)) {
            if (!self::isNonNegativeInteger($attributes['stock'])) {
                $errors['/data/attributes/stock'] = 'The stock must be a non-negative integer.';
            } else {
                $stock = $attributes['stock'];
            }
        }

        if ([] !== $errors) {
            throw JsonApiException::validation($errors);
        }

        $this->catalog->updateBook($bookId, $title, $priceCents, $stock);
        $row = $this->catalog->book($bookId);

        if (0 === (int) $row['published']) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return JsonApi::response([
            'data' => Presenter::book($row),
            'links' => ['self' => "/books/$bookId"],
        ]);
    }

    private static function isNonNegativeInteger(mixed $value): bool
    {
        return \is_int($value) && $value >= 0 && $value <= Catalog::MAX_INTEGER;
    }
}
