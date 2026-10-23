<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Book;
use App\JsonApi\Query;
use App\JsonApi\QueryParameterException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Reads books. Only published books are visible, except to PATCH, which also
 * reaches unpublished books.
 *
 * @implements ProviderInterface<Book>
 */
final class BookProvider implements ProviderInterface
{
    private const INCLUDES = ['author'];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'];

        if ($operation instanceof CollectionOperationInterface) {
            return $this->collection($request);
        }

        return $this->item($operation, (string) ($uriVariables['id'] ?? ''), $request);
    }

    private function collection(Request $request): TraversablePaginator
    {
        Query::includes($request, self::INCLUDES);
        [$page, $size] = Query::pagination($request);

        $query = $this->entityManager->createQueryBuilder()
            ->select('book')
            ->from(Book::class, 'book')
            ->innerJoin('book.author', 'author')
            ->where('book.published = true');

        $this->applyFilters($query, $request);

        $sort = Query::sort($request, [
            'priceCents' => 'book.priceCents',
            'title' => 'book.title',
            'publishedAt' => 'book.publishedAt',
        ]);
        foreach ($sort as [$column, $direction]) {
            $query->addOrderBy($column, $direction);
        }
        $query->addOrderBy('book.id', 'ASC');

        $total = (int) (clone $query)
            ->select('COUNT(book.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $books = $query
            ->setFirstResult(($page - 1) * $size)
            ->setMaxResults($size)
            ->getQuery()
            ->getResult();

        return new TraversablePaginator(new \ArrayIterator($books), (float) $page, (float) $size, (float) $total);
    }

    private function applyFilters(QueryBuilder $query, Request $request): void
    {
        $title = Query::filter($request, 'title');
        if (null !== $title) {
            // The LIKE pattern is written by hand, so its wildcards in the input are escaped.
            $query->andWhere('LOWER(book.title) LIKE :title')
                ->setParameter('title', '%'.addcslashes(mb_strtolower($title), '%_\\').'%');
        }

        $author = Query::filter($request, 'author');
        if (null !== $author) {
            $query->andWhere('author.name = :author')->setParameter('author', $author);
        }

        $minPrice = Query::filter($request, 'minPrice');
        if (null !== $minPrice) {
            $query->andWhere('book.priceCents >= :minPrice')
                ->setParameter('minPrice', Query::integerValue('filter[minPrice]', $minPrice));
        }

        $maxPrice = Query::filter($request, 'maxPrice');
        if (null !== $maxPrice) {
            $query->andWhere('book.priceCents <= :maxPrice')
                ->setParameter('maxPrice', Query::integerValue('filter[maxPrice]', $maxPrice));
        }

        $publishedAfter = Query::filter($request, 'publishedAfter');
        if (null !== $publishedAfter) {
            $query->andWhere('book.publishedAt >= :publishedAfter')
                ->setParameter('publishedAfter', Query::date('filter[publishedAfter]', $publishedAfter), 'date_immutable');
        }

        $publishedBefore = Query::filter($request, 'publishedBefore');
        if (null !== $publishedBefore) {
            $query->andWhere('book.publishedAt <= :publishedBefore')
                ->setParameter('publishedBefore', Query::date('filter[publishedBefore]', $publishedBefore), 'date_immutable');
        }

        $inStock = Query::filter($request, 'inStock');
        if (null !== $inStock) {
            $query->andWhere(Query::boolean('filter[inStock]', $inStock) ? 'book.stock > 0' : 'book.stock = 0');
        }
    }

    private function item(Operation $operation, string $id, Request $request): ?Book
    {
        Query::includes($request, self::INCLUDES);

        $bookId = Query::id($id);
        $book = null === $bookId ? null : $this->entityManager->find(Book::class, $bookId);

        if ('PATCH' !== $operation->getMethod()) {
            return null !== $book && $book->isPublished() ? $book : null;
        }

        if (null === $book) {
            return null;
        }

        $this->assertPatchDocument($request, $id);

        return $book;
    }

    /**
     * The document of a PATCH must name the book of the path.
     */
    private function assertPatchDocument(Request $request, string $id): void
    {
        if (!str_starts_with((string) $request->headers->get('Content-Type'), 'application/vnd.api+json')) {
            throw new UnsupportedMediaTypeHttpException('The Content-Type must be application/vnd.api+json.');
        }

        $document = json_decode($request->getContent(), true);
        if (!\is_array($document) || !\is_array($document['data'] ?? null)) {
            throw new BadRequestHttpException('The body must be a JSON:API document with a "data" object.');
        }

        $data = $document['data'];
        if (isset($data['type']) && 'books' !== $data['type']) {
            throw new ConflictHttpException('The type in the body does not match the resource.');
        }
        if (isset($data['id']) && (string) $data['id'] !== $id) {
            throw new ConflictHttpException('The id in the body does not match the id in the path.');
        }
    }
}
