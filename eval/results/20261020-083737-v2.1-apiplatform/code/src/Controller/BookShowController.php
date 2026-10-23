<?php

namespace App\Controller;

use App\Bookshop\Catalog;
use App\Http\ApiError;
use App\Http\Input;
use Symfony\Component\HttpFoundation\JsonResponse;

final class BookShowController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(string $id): JsonResponse
    {
        $book = $this->catalog->book(Input::pathId($id));
        if (null === $book) {
            throw new ApiError(404, 'Book not found.');
        }

        return new JsonResponse($book);
    }
}
