<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use App\State\BookPatchProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'book')]
#[ApiResource(
    shortName: 'books',
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            parameters: [
                'filter[title]' => new QueryParameter(description: 'Title contains the text, case-insensitive'),
                'filter[author]' => new QueryParameter(description: 'Author name, exact'),
                'filter[minPrice]' => new QueryParameter(description: 'Minimum price in cents, included'),
                'filter[maxPrice]' => new QueryParameter(description: 'Maximum price in cents, included'),
                'filter[publishedAfter]' => new QueryParameter(description: 'Published on or after this date (YYYY-MM-DD)'),
                'filter[publishedBefore]' => new QueryParameter(description: 'Published on or before this date (YYYY-MM-DD)'),
                'filter[inStock]' => new QueryParameter(description: 'true: stock greater than 0, false: stock 0'),
                'sort' => new QueryParameter(description: 'Comma separated fields among priceCents, title, publishedAt; prefix with - for descending'),
                'include' => new QueryParameter(description: 'Related resources to include: author'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'An invalid query parameter (page, sort, include or filter)'),
            ]),
        ),
        new Get(
            uriTemplate: '/books/{id}',
            parameters: [
                'include' => new QueryParameter(description: 'Related resources to include: author'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'An invalid query parameter (include)'),
            ]),
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            processor: BookPatchProcessor::class,
            openapi: new Model\Operation(responses: [
                '204' => new Model\Response(description: 'The book is updated but unpublished, so there is no document to return'),
                '409' => new Model\Response(description: 'The data id differs from the id of the URL'),
                '415' => new Model\Response(description: 'The Content-Type is not application/vnd.api+json'),
            ]),
        ),
    ],
)]
class Book
{
    #[ORM\Id]
    #[ApiProperty(identifier: true, readable: false, writable: false)]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'title', type: 'text')]
    #[Assert\NotBlank]
    private ?string $title = null;

    #[ORM\ManyToOne(targetEntity: Author::class, inversedBy: 'allBooks')]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    #[ApiProperty(writable: false)]
    private ?Author $author = null;

    #[ORM\Column(name: 'published', type: 'boolean')]
    #[ApiProperty(readable: false, writable: false)]
    private bool $published = false;

    #[ORM\Column(name: 'price_cents', type: 'integer')]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private ?int $priceCents = null;

    #[ORM\Column(name: 'published_at', type: 'date_immutable', nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'stock', type: 'integer')]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private ?int $stock = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getAuthor(): ?Author
    {
        return $this->author;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function getPriceCents(): ?int
    {
        return $this->priceCents;
    }

    public function setPriceCents(?int $priceCents): static
    {
        $this->priceCents = $priceCents;

        return $this;
    }

    public function getPublishedAt(): ?string
    {
        return $this->publishedAt?->format('Y-m-d');
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(?int $stock): static
    {
        $this->stock = $stock;

        return $this;
    }
}
