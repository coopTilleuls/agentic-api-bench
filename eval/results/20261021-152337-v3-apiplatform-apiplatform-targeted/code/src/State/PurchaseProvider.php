<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Purchase;
use App\EventListener\CustomerIdentificationListener;
use App\JsonApi\Query;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the purchases of the customer identified by the request. Another
 * customer's purchase is simply not found.
 *
 * @implements ProviderInterface<Purchase>
 */
final class PurchaseProvider implements ProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'];
        $criteria = ['customer' => (int) $request->attributes->get(CustomerIdentificationListener::ATTRIBUTE)];
        $repository = $this->entityManager->getRepository(Purchase::class);

        if (!$operation instanceof CollectionOperationInterface) {
            Query::includes($request, []);
            $purchaseId = Query::id((string) ($uriVariables['id'] ?? ''));

            return null === $purchaseId ? null : $repository->findOneBy(['id' => $purchaseId] + $criteria);
        }

        Query::includes($request, []);
        [$page, $size] = Query::pagination($request);

        $purchases = $repository->findBy($criteria, ['createdAt' => 'DESC', 'id' => 'DESC'], $size, ($page - 1) * $size);

        return new TraversablePaginator(new \ArrayIterator($purchases), (float) $page, (float) $size, (float) $repository->count($criteria));
    }
}
