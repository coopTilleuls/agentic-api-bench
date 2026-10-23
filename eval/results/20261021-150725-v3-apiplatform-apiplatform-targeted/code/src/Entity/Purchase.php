<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase')]
class Purchase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    private Customer $customer;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'total_cents', type: 'integer')]
    private int $totalCents = 0;

    /** @var Collection<int, PurchaseLine> */
    #[ORM\OneToMany(targetEntity: PurchaseLine::class, mappedBy: 'purchase', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct(Customer $customer, \DateTimeImmutable $createdAt, int $totalCents)
    {
        $this->customer = $customer;
        $this->createdAt = $createdAt;
        $this->totalCents = $totalCents;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    /** @return Collection<int, PurchaseLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(PurchaseLine $line): void
    {
        $this->lines->add($line);
    }
}
