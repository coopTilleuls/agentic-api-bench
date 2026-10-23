<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'author')]
#[ApiResource(
    shortName: 'authors',
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            parameters: [
                'include' => new QueryParameter(description: 'Related resources to include: books'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'An invalid query parameter (page or include)'),
            ]),
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            parameters: [
                'include' => new QueryParameter(description: 'Related resources to include: books'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'An invalid query parameter (include)'),
            ]),
        ),
    ],
)]
class Author
{
    #[ORM\Id]
    #[ApiProperty(identifier: true, readable: false, writable: false)]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'text', nullable: true)]
    private ?string $name = null;

    /** @var Collection<int, Book> */
    #[ORM\OneToMany(targetEntity: Book::class, mappedBy: 'author')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $allBooks;

    public function __construct()
    {
        $this->allBooks = new ArrayCollection();
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
     * Published books only, in id order.
     *
     * @return Collection<int, Book>
     */
    public function getBooks(): Collection
    {
        return $this->allBooks->filter(static fn (Book $book): bool => $book->isPublished());
    }

    public function getPublishedBooks(): int
    {
        return $this->getBooks()->count();
    }
}
