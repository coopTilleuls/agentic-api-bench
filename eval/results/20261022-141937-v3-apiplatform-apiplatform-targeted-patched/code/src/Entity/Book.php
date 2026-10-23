<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Filter\ColumnBoundFilter;
use App\Doctrine\Filter\InStockFilter;
use App\Doctrine\Filter\SortListFilter;
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
                'filter[title]' => new QueryParameter(filter: new PartialSearchFilter(), property: 'title', description: 'Title contains the text, case-insensitive.'),
                'filter[author]' => new QueryParameter(filter: new ExactFilter(), property: 'author.name', description: 'Author name, exact.'),
                'filter[minPrice]' => new QueryParameter(filter: new ColumnBoundFilter('>=', 'int'), property: 'priceCents', description: 'Minimum price in cents, inclusive.'),
                'filter[maxPrice]' => new QueryParameter(filter: new ColumnBoundFilter('<=', 'int'), property: 'priceCents', description: 'Maximum price in cents, inclusive.'),
                'filter[publishedAfter]' => new QueryParameter(filter: new ColumnBoundFilter('>=', 'date'), property: 'publishedAt', description: 'Published on or after this date (YYYY-MM-DD).'),
                'filter[publishedBefore]' => new QueryParameter(filter: new ColumnBoundFilter('<=', 'date'), property: 'publishedAt', description: 'Published on or before this date (YYYY-MM-DD).'),
                'filter[inStock]' => new QueryParameter(filter: new InStockFilter(), property: 'stock', description: 'true: stock greater than 0; false: stock 0.'),
                'sort' => new QueryParameter(filter: new SortListFilter(), default: 'id', description: 'Comma separated fields among priceCents, title, publishedAt; prefix with - for descending. Default: id.'),
                'include' => new QueryParameter(description: 'Related resources to include: author.'),
                'page[number]' => new QueryParameter(description: 'Page number, from 1.'),
                'page[size]' => new QueryParameter(description: 'Items per page, from 1 to 50.'),
            ],
        ),
        new Get(
            uriTemplate: '/books/{id}',
            parameters: [
                'include' => new QueryParameter(description: 'Related resources to include: author.'),
            ],
        ),
        new Patch(uriTemplate: '/books/{id}', processor: BookPatchProcessor::class),
    ],
)]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ApiProperty(identifier: true)]
    private ?int $id = null;

    #[ORM\Column(name: 'title', type: 'text')]
    #[Assert\NotBlank]
    private string $title = '';

    #[ORM\ManyToOne(targetEntity: Author::class, inversedBy: 'allBooks')]
    #[ORM\JoinColumn(name: 'author_id', nullable: false)]
    private Author $author;

    #[ORM\Column(name: 'published', type: 'boolean')]
    #[ApiProperty(readable: false, writable: false)]
    private bool $published = false;

    #[ORM\Column(name: 'price_cents', type: 'integer')]
    #[Assert\PositiveOrZero]
    private int $priceCents = 0;

    #[ORM\Column(name: 'published_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'stock', type: 'integer')]
    #[Assert\PositiveOrZero]
    private int $stock = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function setPriceCents(int $priceCents): void
    {
        $this->priceCents = $priceCents;
    }

    public function getPublishedAt(): ?string
    {
        return $this->publishedAt?->format('Y-m-d');
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): void
    {
        $this->stock = $stock;
    }

    public function decreaseStock(int $quantity): void
    {
        $this->stock -= $quantity;
    }
}
