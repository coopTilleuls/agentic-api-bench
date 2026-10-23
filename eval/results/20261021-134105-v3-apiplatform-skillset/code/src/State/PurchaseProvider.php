<?php

namespace App\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PurchaseResource;
use App\Customer\CurrentCustomer;
use App\Entity\Customer;
use App\Entity\Purchase;
use App\Entity\PurchaseLine;
use App\JsonApi\JsonApiCollection;
use App\JsonApi\Identifier;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProviderInterface<PurchaseResource|JsonApiCollection>
 */
final class PurchaseProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QueryParser $query,
        private readonly CurrentCustomer $currentCustomer,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseResource|JsonApiCollection
    {
        $customer = $this->currentCustomer->get();
        $this->query->include([]);

        if ($operation instanceof GetCollection) {
            return $this->collection($customer);
        }
        if (!$operation instanceof Get) {
            throw JsonApiException::error(405, 'Method not allowed.');
        }

        $purchase = $this->find($uriVariables['id'] ?? null, $customer);
        if (null === $purchase) {
            throw JsonApiException::error(404, 'Purchase not found.');
        }

        return $this->resources([$purchase])[0];
    }

    private function collection(Customer $customer): JsonApiCollection
    {
        $this->query->sort([]);
        $page = $this->query->pagination();

        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Purchase::class, 'p')
            ->where('p.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $purchases = $qb
            ->setFirstResult(($page['number'] - 1) * $page['size'])
            ->setMaxResults($page['size'])
            ->getQuery()
            ->getResult();

        return new JsonApiCollection($this->resources($purchases), $total, $page['number'], $page['size']);
    }

    /**
     * @param list<Purchase> $purchases
     *
     * @return list<PurchaseResource>
     */
    private function resources(array $purchases): array
    {
        if ([] === $purchases) {
            return [];
        }

        $lines = $this->em->createQueryBuilder()
            ->select('l', 'b')
            ->from(PurchaseLine::class, 'l')
            ->join('l.book', 'b')
            ->where('IDENTITY(l.purchase) IN (:ids)')
            ->setParameter('ids', array_map(static fn (Purchase $purchase): int => (int) $purchase->getId(), $purchases))
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        $linesByPurchase = [];
        foreach ($lines as $line) {
            $linesByPurchase[$line->getPurchase()->getId()][] = [
                'bookId' => (int) $line->getBook()->getId(),
                'title' => $line->getBook()->getTitle(),
                'quantity' => $line->getQuantity(),
                'unitPriceCents' => $line->getUnitPriceCents(),
            ];
        }

        return array_map(static fn (Purchase $purchase): PurchaseResource => PurchaseResource::create(
            (int) $purchase->getId(),
            $purchase->getCreatedAt(),
            $purchase->getTotalCents(),
            $linesByPurchase[$purchase->getId()] ?? [],
        ), $purchases);
    }

    private function find(mixed $id, Customer $customer): ?Purchase
    {
        $id = Identifier::fromPath($id);
        if (null === $id) {
            return null;
        }

        $purchase = $this->em->find(Purchase::class, $id);

        return null !== $purchase && $purchase->getCustomer()->getId() === $customer->getId() ? $purchase : null;
    }
}
