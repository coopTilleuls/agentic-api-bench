<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use App\State\AuthorProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'author')]
#[ApiResource(
    shortName: 'authors',
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            provider: AuthorProvider::class,
            paginationEnabled: false,
            parameters: [
                'page[number]' => new QueryParameter(constraints: [], description: 'Page number, from 1.', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
                'page[size]' => new QueryParameter(constraints: [], description: 'Items per page, from 1 to 50.', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]),
                'include' => new QueryParameter(constraints: [], description: 'Related resources to include in the compound document: books.', schema: ['type' => 'string', 'enum' => ['books']]),
            ],
            openapi: new Model\Operation(
                responses: [
                    '400' => new Model\Response(description: 'A query parameter is invalid.'),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            provider: AuthorProvider::class,
            parameters: [
                'include' => new QueryParameter(constraints: [], description: 'Related resources to include in the compound document: books.', schema: ['type' => 'string', 'enum' => ['books']]),
            ],
            openapi: new Model\Operation(
                responses: [
                    '404' => new Model\Response(description: 'Unknown author, or malformed id.'),
                ],
            ),
        ),
    ],
    formats: ['jsonapi' => ['application/vnd.api+json']],
)]
class Author
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ApiProperty(identifier: true)]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, nullable: true)]
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
     * Only the published books, in id order.
     *
     * @return list<Book>
     */
    public function getBooks(): array
    {
        return array_values($this->allBooks->filter(static fn (Book $book): bool => $book->isPublished())->toArray());
    }

    public function getPublishedBooks(): int
    {
        return \count($this->getBooks());
    }
}
