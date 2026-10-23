<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\State\BookProcessor;
use App\State\BookProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'book')]
#[ApiResource(
    shortName: 'books',
    operations: [
        new GetCollection(uriTemplate: '/books', provider: BookProvider::class),
        new Get(uriTemplate: '/books/{id}', provider: BookProvider::class),
        new Patch(uriTemplate: '/books/{id}', provider: BookProvider::class, processor: BookProcessor::class),
    ],
)]
class Book
{
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[ORM\Column(name: 'title', type: 'text')]
    private string $title = '';

    #[ORM\ManyToOne(targetEntity: Author::class, inversedBy: 'books')]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    private Author $author;

    #[ApiProperty(readable: false, writable: false)]
    #[ORM\Column(name: 'published', type: 'boolean')]
    private bool $published = false;

    #[Assert\GreaterThanOrEqual(0)]
    #[Assert\LessThanOrEqual(2147483647)]
    #[ORM\Column(name: 'price_cents', type: 'integer')]
    private int $priceCents = 0;

    #[ORM\Column(name: 'published_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[Assert\GreaterThanOrEqual(0)]
    #[Assert\LessThanOrEqual(2147483647)]
    #[ORM\Column(name: 'stock', type: 'integer')]
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

    public function decreaseStock(int $quantity): void
    {
        $this->stock -= $quantity;
    }
}
