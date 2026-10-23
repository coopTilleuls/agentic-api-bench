<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use App\State\PurchaseCreateProcessor;
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
        new GetCollection(
            uriTemplate: '/purchases',
            provider: PurchaseProvider::class,
            paginationEnabled: false,
            parameters: [
                'Customer-Id' => new HeaderParameter(constraints: [], description: 'Id of the customer whose purchases are listed.', schema: ['type' => 'string'], required: true),
                'page[number]' => new QueryParameter(constraints: [], description: 'Page number, from 1.', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
                'page[size]' => new QueryParameter(constraints: [], description: 'Items per page, from 1 to 50.', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]),
            ],
            openapi: new Model\Operation(
                responses: [
                    '400' => new Model\Response(description: 'A query parameter is invalid.'),
                    '401' => new Model\Response(description: 'The Customer-Id header is missing or unknown.'),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            provider: PurchaseProvider::class,
            parameters: [
                'Customer-Id' => new HeaderParameter(constraints: [], description: 'Id of the customer who made the purchase.', schema: ['type' => 'string'], required: true),
            ],
            openapi: new Model\Operation(
                responses: [
                    '401' => new Model\Response(description: 'The Customer-Id header is missing or unknown.'),
                    '404' => new Model\Response(description: 'Unknown purchase, another customer\'s purchase, or malformed id.'),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/purchases',
            processor: PurchaseCreateProcessor::class,
            input: false,
            parameters: [
                'Customer-Id' => new HeaderParameter(constraints: [], description: 'Id of the customer buying the books.', schema: ['type' => 'string'], required: true),
            ],
            openapi: new Model\Operation(
                requestBody: new Model\RequestBody(
                    description: 'A JSON:API document with the lines to buy.',
                    content: new \ArrayObject([
                        'application/vnd.api+json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['data'],
                                'properties' => [
                                    'data' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'type' => ['type' => 'string', 'enum' => ['purchases']],
                                            'attributes' => [
                                                'type' => 'object',
                                                'required' => ['lines'],
                                                'properties' => [
                                                    'lines' => [
                                                        'type' => 'array',
                                                        'minItems' => 1,
                                                        'items' => [
                                                            'type' => 'object',
                                                            'required' => ['bookId', 'quantity'],
                                                            'properties' => [
                                                                'bookId' => ['type' => 'integer'],
                                                                'quantity' => ['type' => 'integer', 'minimum' => 1],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                    required: true,
                ),
                responses: [
                    '201' => new Model\Response(description: 'The purchase; its URL is in the Location header.'),
                    '401' => new Model\Response(description: 'The Customer-Id header is missing or unknown.'),
                    '402' => new Model\Response(description: 'The payment was declined. Nothing is stored.'),
                    '409' => new Model\Response(description: 'Not enough stock for a book. Nothing is stored.'),
                    '415' => new Model\Response(description: 'The Content-Type is not application/vnd.api+json.'),
                    '422' => new Model\Response(description: 'Invalid lines, or a book that is not published.'),
                    '502' => new Model\Response(description: 'The payment service is unreachable or failed. Nothing is stored.'),
                ],
            ),
        ),
    ],
    formats: ['jsonapi' => ['application/vnd.api+json']],
    normalizationContext: ['datetime_format' => 'Y-m-d\TH:i:s\Z', 'datetime_timezone' => 'UTC'],
)]
class Purchase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ApiProperty(identifier: true)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    #[ApiProperty(readable: false, writable: false)]
    private Customer $customer;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'total_cents', type: Types::INTEGER)]
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

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function addPurchaseLine(PurchaseLine $line): void
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
                'bookId' => $line->getBook()->getId(),
                'title' => $line->getBook()->getTitle(),
                'quantity' => $line->getQuantity(),
                'unitPriceCents' => $line->getUnitPriceCents(),
            ],
            $this->purchaseLines->toArray(),
        ));
    }
}
