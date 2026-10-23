<?php

namespace App\Controller;

use App\Bookshop\Catalog;
use App\Http\Input;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookUpdateController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $book = $this->catalog->update(Input::pathId($id), Input::jsonObject($request));

        // Unpublished books are updatable but not visible: nothing to show.
        return null === $book ? new Response(status: 204) : new JsonResponse($book);
    }
}
