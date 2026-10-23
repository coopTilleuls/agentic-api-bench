<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\AuthorProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'author')]
#[ApiResource(
    shortName: 'authors',
    operations: [
        new GetCollection(uriTemplate: '/authors', provider: AuthorProvider::class),
        new Get(uriTemplate: '/authors/{id}', provider: AuthorProvider::class),
    ],
)]
class Author
{
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'text', nullable: true)]
    private ?string $name = null;

    /** @var Collection<int, Book> */
    #[ORM\OneToMany(targetEntity: Book::class, mappedBy: 'author')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $books;

    public function __construct()
    {
        $this->books = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * The published books of this author, in id order.
     *
     * @return Collection<int, Book>
     */
    public function getBooks(): Collection
    {
        return $this->books->filter(static fn (Book $book): bool => $book->isPublished());
    }

    public function getPublishedBooks(): int
    {
        return $this->getBooks()->count();
    }
}
