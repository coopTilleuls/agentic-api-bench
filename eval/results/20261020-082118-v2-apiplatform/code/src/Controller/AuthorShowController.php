<?php

namespace App\Controller;

use App\Bookshop\Catalog;
use App\Http\ApiError;
use App\Http\Input;
use Symfony\Component\HttpFoundation\JsonResponse;

final class AuthorShowController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(string $id): JsonResponse
    {
        $author = $this->catalog->author(Input::pathId($id));
        if (null === $author) {
            throw new ApiError(404, 'Author not found.');
        }

        return new JsonResponse($author);
    }
}
