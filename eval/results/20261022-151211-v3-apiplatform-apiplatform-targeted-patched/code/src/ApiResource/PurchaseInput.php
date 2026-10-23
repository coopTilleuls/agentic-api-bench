<?php

namespace App\ApiResource;

/**
 * Body of POST /purchases. Each line is checked by the processor, which answers with JSON:API pointers.
 */
final class PurchaseInput
{
    /** @var list<array{bookId: int, quantity: int}> */
    public array $lines = [];
}
