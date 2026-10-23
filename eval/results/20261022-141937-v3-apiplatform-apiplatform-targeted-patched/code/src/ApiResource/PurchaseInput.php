<?php

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /purchases. Lines are checked in the processor, which knows the books.
 */
final class PurchaseInput
{
    /** @var list<array{bookId?: mixed, quantity?: mixed}> */
    #[Assert\Type('array')]
    public array $lines = [];
}
