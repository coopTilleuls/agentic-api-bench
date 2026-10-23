<?php

namespace App\ApiResource\Input;

/**
 * Attributes are untyped so that the body is validated by the processor, which answers 422 with JSON:API pointers.
 */
final class BookUpdate
{
    public mixed $title = null;
    public mixed $priceCents = null;
    public mixed $stock = null;
}
