<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiException;
use App\Api\CustomerIdListener;
use App\Api\QueryParams;
use App\Entity\Purchase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the purchases of the customer named by the Customer-Id header.
 */
final class PurchaseProvider implements ProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        /** @var Request $request */
        $request = $context['request'];
        $customerId = (int) $request->attributes->get(CustomerIdListener::ATTRIBUTE);
        QueryParams::includes($request, []);

        if ($operation instanceof CollectionOperationInterface) {
            $page = QueryParams::page($request);
            $query = $this->entityManager->createQueryBuilder()
                ->from(Purchase::class, 'p')
                ->where('p.customer = :customer')
                ->setParameter('customer', $customerId);

            $total = (int) (clone $query)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
            $purchases = $query->select('p')
                ->orderBy('p.createdAt', 'DESC')
                ->addOrderBy('p.id', 'DESC')
                ->setFirstResult(($page['number'] - 1) * $page['size'])
                ->setMaxResults($page['size'])
                ->getQuery()
                ->getResult();

            return new TraversablePaginator(new \ArrayIterator($purchases), $page['number'], $page['size'], $total);
        }

        $purchase = $this->entityManager->getRepository(Purchase::class)->findOneBy([
            'id' => QueryParams::id($uriVariables['id'] ?? null),
            'customer' => $customerId,
        ]);

        return $purchase ?? throw ApiException::status(404, 'Purchase not found.');
    }
}
