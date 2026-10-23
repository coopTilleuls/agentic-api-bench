<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Author;
use App\ApiResource\Book;
use App\JsonApi\JsonApiException;
use App\JsonApi\Paginator;
use App\JsonApi\Query;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<Book>
 */
final class BookProvider implements ProviderInterface
{
    private const SORT_COLUMNS = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

    private const SELECT = <<<'SQL'
        SELECT b.id, b.title, b.price_cents, b.published, b.published_at, b.stock, a.id AS author_id, a.name AS author_name,
            (SELECT COUNT(*) FROM book pb WHERE pb.author_id = a.id AND pb.published) AS author_published_books
        FROM book b
        JOIN author a ON a.id = b.author_id
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuthorProvider $authors,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'];
        $includes = Query::include($request, ['author']);
        if ($includes) {
            $request->attributes->set('_api_included', $includes);
        }

        if ($operation instanceof CollectionOperationInterface) {
            return $this->collection($request, $includes);
        }

        $book = $this->find((int) $uriVariables['id'], !$operation instanceof Patch);
        if (null === $book) {
            throw JsonApiException::single(404, 'Not Found', sprintf('Book %d does not exist.', $uriVariables['id']));
        }

        if (\in_array('author', $includes, true)) {
            $this->authors->loadBooks([$book->author]);
        }

        return $book;
    }

    /**
     * @param bool $publishedOnly whether an unpublished book is reported as absent
     */
    public function find(int $id, bool $publishedOnly = true): ?Book
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT.' WHERE b.id = ?'.($publishedOnly ? ' AND b.published = true' : ''),
            [$id],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @param list<string> $includes
     */
    private function collection(Request $request, array $includes): Paginator
    {
        [$number, $size] = Query::page($request);
        $sorts = Query::sort($request, self::SORT_COLUMNS);

        $qb = $this->connection->createQueryBuilder()
            ->select('b.id', 'b.title', 'b.price_cents', 'b.published', 'b.published_at', 'b.stock', 'a.id AS author_id', 'a.name AS author_name',
                '(SELECT COUNT(*) FROM book pb WHERE pb.author_id = a.id AND pb.published) AS author_published_books')
            ->from('book', 'b')
            ->join('b', 'author', 'a', 'a.id = b.author_id')
            ->where('b.published = true');

        if (null !== ($title = Query::filter($request, 'title'))) {
            $qb->andWhere('b.title ILIKE :title')
                ->setParameter('title', '%'.addcslashes($title, '\\%_').'%');
        }
        if (null !== ($author = Query::filter($request, 'author'))) {
            $qb->andWhere('a.name = :author')->setParameter('author', $author);
        }
        if (null !== ($minPrice = Query::integerFilter($request, 'minPrice'))) {
            $qb->andWhere('b.price_cents >= :minPrice')->setParameter('minPrice', $minPrice, ParameterType::INTEGER);
        }
        if (null !== ($maxPrice = Query::integerFilter($request, 'maxPrice'))) {
            $qb->andWhere('b.price_cents <= :maxPrice')->setParameter('maxPrice', $maxPrice, ParameterType::INTEGER);
        }
        if (null !== ($publishedAfter = Query::dateFilter($request, 'publishedAfter'))) {
            $qb->andWhere('b.published_at >= :publishedAfter')->setParameter('publishedAfter', $publishedAfter);
        }
        if (null !== ($publishedBefore = Query::dateFilter($request, 'publishedBefore'))) {
            $qb->andWhere('b.published_at <= :publishedBefore')->setParameter('publishedBefore', $publishedBefore);
        }
        if (null !== ($inStock = Query::booleanFilter($request, 'inStock'))) {
            $qb->andWhere($inStock ? 'b.stock > 0' : 'b.stock = 0');
        }

        $countQb = clone $qb;
        $countQb->select('COUNT(*)');
        $total = (int) $countQb->executeQuery()->fetchOne();

        foreach ($sorts as [$field, $direction]) {
            $qb->addOrderBy(self::SORT_COLUMNS[$field], $direction);
        }
        $qb->addOrderBy('b.id', 'ASC')
            ->setFirstResult(($number - 1) * $size)
            ->setMaxResults($size);

        $books = array_map($this->hydrate(...), $qb->executeQuery()->fetchAllAssociative());

        if (\in_array('author', $includes, true)) {
            $authors = [];
            foreach ($books as $book) {
                $authors[$book->author->id] = $book->author;
            }
            $this->authors->loadBooks(array_values($authors));
        }

        return new Paginator($books, $total, $number, $size);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Book
    {
        $author = new Author();
        $author->id = (int) $row['author_id'];
        $author->name = $row['author_name'];
        $author->publishedBooks = (int) $row['author_published_books'];

        $book = new Book();
        $book->id = (int) $row['id'];
        $book->title = $row['title'];
        $book->priceCents = (int) $row['price_cents'];
        $book->publishedAt = $row['published_at'];
        $book->stock = (int) $row['stock'];
        $book->author = $author;

        return $book;
    }
}
