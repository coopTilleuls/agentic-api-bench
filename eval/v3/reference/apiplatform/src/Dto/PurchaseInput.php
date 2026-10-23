<?php

namespace App\Dto;

use App\Validator\PublishedBook;
use Symfony\Component\Validator\Constraints as Assert;

final class PurchaseInput
{
    /** @var list<array{bookId: int, quantity: int}> */
    #[Assert\NotNull]
    #[Assert\Type('array')]
    #[Assert\Count(min: 1)]
    #[Assert\All([new Assert\Collection(
        fields: [
            'bookId' => [new Assert\NotNull(), new Assert\Type('int'), new PublishedBook()],
            'quantity' => [new Assert\NotNull(), new Assert\Type('int'), new Assert\GreaterThanOrEqual(1)],
        ],
        allowExtraFields: true,
    )])]
    public mixed $lines = null;
}
