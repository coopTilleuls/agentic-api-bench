<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\InStockFilter;
use App\Doctrine\ScalarComparisonFilter;
use App\Doctrine\SortListFilter;
use App\State\BookPatchProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'book')]
#[ApiResource(
    shortName: 'books',
    operations: [
        new GetCollection(uriTemplate: '/books', parameters: [
            'filter[title]' => new QueryParameter(filter: new PartialSearchFilter(), property: 'title'),
            'filter[author]' => new QueryParameter(filter: new ExactFilter(), property: 'author.name'),
            'filter[minPrice]' => new QueryParameter(filter: new ScalarComparisonFilter('>='), property: 'priceCents', constraints: [new Assert\Length(min: 1), new Assert\Regex('/^\d+$/')]),
            'filter[maxPrice]' => new QueryParameter(filter: new ScalarComparisonFilter('<='), property: 'priceCents', constraints: [new Assert\Length(min: 1), new Assert\Regex('/^\d+$/')]),
            'filter[publishedAfter]' => new QueryParameter(filter: new ScalarComparisonFilter('>='), property: 'publishedAt', constraints: [new Assert\Length(min: 1), new Assert\Date()]),
            'filter[publishedBefore]' => new QueryParameter(filter: new ScalarComparisonFilter('<='), property: 'publishedAt', constraints: [new Assert\Length(min: 1), new Assert\Date()]),
            'filter[inStock]' => new QueryParameter(filter: new InStockFilter(), property: 'stock', constraints: [new Assert\Choice(['true', 'false'])]),
            'sort' => new QueryParameter(filter: new SortListFilter(), constraints: [new Assert\Regex('/^-?(priceCents|title|publishedAt)(,-?(priceCents|title|publishedAt))*$/')]),
        ]),
        new Get(uriTemplate: '/books/{id}'),
        new Patch(uriTemplate: '/books/{id}', denormalizationContext: ['groups' => ['book:write']], processor: BookPatchProcessor::class),
    ],
    normalizationContext: ['groups' => ['book:read'], 'skip_null_values' => false],
    parameters: [
        'page[size]' => new QueryParameter(constraints: [new Assert\Type('digit'), new Assert\Range(min: 1, max: 50)]),
        'page[number]' => new QueryParameter(constraints: [new Assert\Type('digit'), new Assert\GreaterThanOrEqual(1)]),
        'include' => new QueryParameter(constraints: [new Assert\Regex('/^author(,author)*$/')]),
    ],
)]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    #[Groups(['book:read', 'book:write', 'author:item'])]
    #[Assert\NotBlank]
    private string $title;

    #[ORM\ManyToOne(targetEntity: Author::class, inversedBy: 'books')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['book:read'])]
    private Author $author;

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column]
    #[Groups(['book:read', 'book:write'])]
    #[Assert\PositiveOrZero]
    private int $priceCents;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['book:read'])]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    #[Groups(['book:read', 'book:write'])]
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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): void
    {
        $this->stock = $stock;
    }
}
