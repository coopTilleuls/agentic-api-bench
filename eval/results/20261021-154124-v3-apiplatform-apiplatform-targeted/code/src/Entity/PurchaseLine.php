<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_line')]
class PurchaseLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Purchase::class, inversedBy: 'purchaseLines')]
    #[ORM\JoinColumn(name: 'purchase_id', referencedColumnName: 'id', nullable: false)]
    private ?Purchase $purchase = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false)]
    private ?Book $book = null;

    #[ORM\Column(name: 'quantity', type: 'integer')]
    private int $quantity = 0;

    #[ORM\Column(name: 'unit_price_cents', type: 'integer')]
    private int $unitPriceCents = 0;

    public function __construct(Purchase $purchase, Book $book, int $quantity, int $unitPriceCents)
    {
        $this->purchase = $purchase;
        $this->book = $book;
        $this->quantity = $quantity;
        $this->unitPriceCents = $unitPriceCents;
    }

    public function getId(): ?int
    {
        return $this->id;
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
