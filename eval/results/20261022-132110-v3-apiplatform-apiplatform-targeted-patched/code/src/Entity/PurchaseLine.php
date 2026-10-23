<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_line')]
class PurchaseLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Purchase::class, inversedBy: 'purchaseLines')]
    #[ORM\JoinColumn(name: 'purchase_id', referencedColumnName: 'id', nullable: false)]
    private Purchase $purchase;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false)]
    private Book $book;

    #[ORM\Column(name: 'quantity', type: Types::INTEGER)]
    private int $quantity;

    #[ORM\Column(name: 'unit_price_cents', type: Types::INTEGER)]
    private int $unitPriceCents;

    public function __construct(Purchase $purchase, Book $book, int $quantity, int $unitPriceCents)
    {
        $this->purchase = $purchase;
        $this->book = $book;
        $this->quantity = $quantity;
        $this->unitPriceCents = $unitPriceCents;
        $purchase->addPurchaseLine($this);
    }

    public function getBook(): Book
    {
        return $this->book;
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
