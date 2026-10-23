<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\ApiResource\PurchaseInput;
use App\State\PurchaseProcessor;
use App\State\PurchaseProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase')]
#[ApiResource(
    shortName: 'purchases',
    operations: [
        new GetCollection(uriTemplate: '/purchases', provider: PurchaseProvider::class),
        new Get(uriTemplate: '/purchases/{id}', provider: PurchaseProvider::class),
        new Post(uriTemplate: '/purchases', input: PurchaseInput::class, processor: PurchaseProcessor::class, read: false),
    ],
    normalizationContext: ['datetime_format' => \DATE_ATOM, 'datetime_timezone' => 'UTC'],
)]
class Purchase
{
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ApiProperty(readable: false, writable: false)]
    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    private Customer $customer;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'total_cents', type: 'integer')]
    private int $totalCents = 0;

    /** @var Collection<int, PurchaseLine> */
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\OneToMany(targetEntity: PurchaseLine::class, mappedBy: 'purchase', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $purchaseLines;

    public function __construct(Customer $customer, \DateTimeImmutable $createdAt, int $totalCents)
    {
        $this->customer = $customer;
        $this->createdAt = $createdAt;
        $this->totalCents = $totalCents;
        $this->purchaseLines = new ArrayCollection();
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

    public function addLine(Book $book, int $quantity): void
    {
        $line = new PurchaseLine($this, $book, $quantity, $book->getPriceCents());
        $this->purchaseLines->add($line);
    }

    /**
     * @return list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}>
     */
    public function getLines(): array
    {
        return array_values(array_map(
            static fn (PurchaseLine $line): array => [
                'bookId' => $line->getBook()->getId(),
                'title' => $line->getBook()->getTitle(),
                'quantity' => $line->getQuantity(),
                'unitPriceCents' => $line->getUnitPriceCents(),
            ],
            $this->purchaseLines->toArray(),
        ));
    }
}
