<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\PurchaseInput;
use App\State\PurchaseProcessor;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'purchase')]
#[ApiResource(
    shortName: 'purchases',
    operations: [
        new GetCollection(uriTemplate: '/purchases'),
        new Get(uriTemplate: '/purchases/{id}'),
        new Post(uriTemplate: '/purchases', input: PurchaseInput::class, processor: PurchaseProcessor::class),
    ],
    normalizationContext: ['groups' => ['purchase:read']],
    order: ['createdAt' => 'DESC', 'id' => 'DESC'],
    parameters: [
        'Customer-Id' => new HeaderParameter(required: true, schema: ['type' => 'integer']),
        'page[size]' => new QueryParameter(constraints: [new Assert\Type('digit'), new Assert\Range(min: 1, max: 50)]),
        'page[number]' => new QueryParameter(constraints: [new Assert\Type('digit'), new Assert\GreaterThanOrEqual(1)]),
        'include' => new QueryParameter(constraints: [new Assert\Blank()]),
    ],
)]
class Purchase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, PurchaseLine> */
    #[ORM\OneToMany(targetEntity: PurchaseLine::class, mappedBy: 'purchase', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[Groups(['purchase:read'])]
    private Collection $lines;

    #[ORM\Column(type: 'datetimetz_immutable')]
    #[Groups(['purchase:read'])]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Customer $customer,
        #[ORM\Column]
        #[Groups(['purchase:read'])]
        private int $totalCents,
    ) {
        $this->lines = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function addLine(Book $book, int $quantity, int $unitPriceCents): void
    {
        $this->lines->add(new PurchaseLine($this, $book, $quantity, $unitPriceCents));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }
}
