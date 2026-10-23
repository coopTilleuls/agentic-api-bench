<?php

namespace App\ApiResource\Input;

/**
 * Untyped, so that the processor validates the lines and answers 422 with JSON:API pointers.
 */
final class PurchaseCreate
{
    public mixed $lines = null;
}
