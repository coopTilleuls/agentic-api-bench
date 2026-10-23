<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Input\PurchaseInput;
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
                'Customer-Id' => new HeaderParameter(required: true, description: 'Id of the customer'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'An invalid query parameter (page or include)'),
                '401' => new Model\Response(description: 'Missing, malformed or unknown Customer-Id'),
            ]),
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            parameters: [
                'Customer-Id' => new HeaderParameter(required: true, description: 'Id of the customer'),
            ],
            openapi: new Model\Operation(responses: [
                '401' => new Model\Response(description: 'Missing, malformed or unknown Customer-Id'),
            ]),
        ),
        new Post(
            uriTemplate: '/purchases',
            input: PurchaseInput::class,
            processor: PurchaseProcessor::class,
            parameters: [
                'Customer-Id' => new HeaderParameter(required: true, description: 'Id of the customer'),
            ],
            openapi: new Model\Operation(responses: [
                '401' => new Model\Response(description: 'Missing, malformed or unknown Customer-Id'),
                '402' => new Model\Response(description: 'The payment service declined the payment'),
                '409' => new Model\Response(description: 'Not enough stock for one of the books'),
                '502' => new Model\Response(description: 'The payment service is unreachable, failed or gave an unexpected answer'),
            ]),
        ),
    ],
)]
class Purchase
{
    #[ORM\Id]
    #[ApiProperty(identifier: true, readable: false, writable: false)]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    #[ApiProperty(readable: false, writable: false)]
    private ?Customer $customer = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'total_cents', type: 'integer')]
    private int $totalCents = 0;

    /** @var Collection<int, PurchaseLine> */
    #[ORM\OneToMany(targetEntity: PurchaseLine::class, mappedBy: 'purchase', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[ApiProperty(readable: false, writable: false)]
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

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
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
        $lines = [];
        foreach ($this->purchaseLines as $line) {
            $lines[] = [
                'bookId' => $line->getBook()->getId(),
                'title' => $line->getBook()->getTitle(),
                'quantity' => $line->getQuantity(),
                'unitPriceCents' => $line->getUnitPriceCents(),
            ];
        }

        return $lines;
    }
}
