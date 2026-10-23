<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'author')]
#[ApiResource(
    shortName: 'authors',
    operations: [
        new GetCollection(uriTemplate: '/authors', normalizationContext: ['groups' => ['author:list']]),
        new Get(uriTemplate: '/authors/{id}', normalizationContext: ['groups' => ['author:item']]),
    ],
    order: ['id' => 'ASC'],
    parameters: [
        'page[size]' => new QueryParameter(constraints: [new Assert\Type('digit'), new Assert\Range(min: 1, max: 50)]),
        'page[number]' => new QueryParameter(constraints: [new Assert\Type('digit'), new Assert\GreaterThanOrEqual(1)]),
        'include' => new QueryParameter(constraints: [new Assert\Regex('/^books(,books)*$/')]),
    ],
)]
class Author
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['author:list', 'author:item', 'book:read'])]
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

    /** @return list<Book> */
    #[Groups(['author:item'])]
    #[SerializedName('books')]
    public function getPublishedBookList(): array
    {
        return array_values($this->books->filter(static fn (Book $book): bool => $book->isPublished())->toArray());
    }

    #[Groups(['author:list', 'author:item'])]
    public function getPublishedBooks(): int
    {
        return \count($this->getPublishedBookList());
    }
}
