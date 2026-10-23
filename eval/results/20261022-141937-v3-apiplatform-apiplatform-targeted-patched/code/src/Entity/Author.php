<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'author')]
#[ApiResource(
    shortName: 'authors',
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            parameters: ['include' => new QueryParameter(description: 'Related resources to include: books.')],
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            parameters: ['include' => new QueryParameter(description: 'Related resources to include: books.')],
        ),
    ],
)]
class Author
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ApiProperty(identifier: true)]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'text', nullable: true)]
    private ?string $name = null;

    /** @var Collection<int, Book> */
    #[ORM\OneToMany(targetEntity: Book::class, mappedBy: 'author')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $allBooks;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Published books of this author, in id order.
     *
     * @return list<Book>
     */
    public function getBooks(): array
    {
        return array_values(array_filter(
            $this->allBooks->toArray(),
            static fn (Book $book): bool => $book->isPublished(),
        ));
    }

    public function getPublishedBooks(): int
    {
        return \count($this->getBooks());
    }
}
