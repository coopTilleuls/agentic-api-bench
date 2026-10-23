<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\ApiResource\PurchaseInput;
use App\State\PurchaseProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase')]
#[ApiResource(
    shortName: 'purchases',
    operations: [
        new GetCollection(
            uriTemplate: '/purchases',
            parameters: [
                'page[number]' => new QueryParameter(description: 'Page number, from 1.'),
                'page[size]' => new QueryParameter(description: 'Items per page, from 1 to 50.'),
            ],
        ),
        new Get(uriTemplate: '/purchases/{id}'),
        new Post(
            uriTemplate: '/purchases',
            input: PurchaseInput::class,
            processor: PurchaseProcessor::class,
        ),
    ],
)]
class Purchase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ApiProperty(identifier: true)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false)]
    #[ApiProperty(readable: false, writable: false)]
    private Customer $customer;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'total_cents', type: 'integer')]
    private int $totalCents = 0;

    /** @var Collection<int, PurchaseLine> */
    #[ORM\OneToMany(targetEntity: PurchaseLine::class, mappedBy: 'purchase', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $purchaseLines;

    public function __construct(Customer $customer, \DateTimeImmutable $createdAt)
    {
        $this->customer = $customer;
        $this->createdAt = $createdAt;
        $this->purchaseLines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    /**
     * ISO 8601 timestamp in UTC.
     */
    public function getCreatedAt(): string
    {
        return $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function setTotalCents(int $totalCents): void
    {
        $this->totalCents = $totalCents;
    }

    public function addLine(PurchaseLine $line): void
    {
        $this->purchaseLines->add($line);
    }

    /**
     * @return list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}>
     */
    public function getLines(): array
    {
        return array_values(array_map(
            static fn (PurchaseLine $line): array => [
                'bookId' => $line->getBookId(),
                'title' => $line->getBook()->getTitle(),
                'quantity' => $line->getQuantity(),
                'unitPriceCents' => $line->getUnitPriceCents(),
            ],
            $this->purchaseLines->toArray(),
        ));
    }
}
