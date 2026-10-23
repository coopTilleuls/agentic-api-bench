<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_line')]
class PurchaseLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lines')]
        #[ORM\JoinColumn(nullable: false)]
        private Purchase $purchase,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Book $book,
        #[ORM\Column]
        #[Groups(['purchase:read'])]
        private int $quantity,
        #[ORM\Column]
        #[Groups(['purchase:read'])]
        private int $unitPriceCents,
    ) {
    }

    #[Groups(['purchase:read'])]
    public function getBookId(): ?int
    {
        return $this->book->getId();
    }

    #[Groups(['purchase:read'])]
    public function getTitle(): string
    {
        return $this->book->getTitle();
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }
}
