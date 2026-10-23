<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\Response;
use LaravelJsonApi\Laravel\Http\Controllers\JsonApiController;

class BookController extends JsonApiController
{
    public function updated(Book $book): ?Response
    {
        return $book->published ? null : response()->noContent();
    }
}
