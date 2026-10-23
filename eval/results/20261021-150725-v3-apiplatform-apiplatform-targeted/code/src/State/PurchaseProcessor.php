<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Error\JsonApiException;
use App\ApiResource\PurchaseResource;
use App\Entity\Book;
use App\Entity\Customer;
use App\Entity\Purchase;
use App\Entity\PurchaseLine;
use App\Payment\PaymentClient;
use App\Payment\PaymentDeclinedException;
use App\Payment\PaymentUnavailableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * POST /purchases: checks the lines, takes the payment, then stores the purchase and decreases the stock.
 * Everything happens in one transaction: any failure stores nothing and changes no stock.
 *
 * @implements ProcessorInterface<PurchaseResource, PurchaseResource>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResourceMapper $mapper,
        private readonly PaymentClient $payment,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseResource
    {
        $customerId = (int) $this->requestStack->getCurrentRequest()?->attributes->get('customer_id');
        $lines = $this->validLines($data->lines);

        $purchase = $this->entityManager->wrapInTransaction(function () use ($lines, $customerId): Purchase {
            $books = $this->lockBooks(array_unique(array_column($lines, 'bookId')));
            $errors = [];
            $quantities = [];

            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']] ?? null;

                if (null === $book || !$book->isPublished()) {
                    $errors[] = JsonApiException::error(422, \sprintf('Book %d is not available.', $line['bookId']), \sprintf('/data/attributes/lines/%d/bookId', $index));
                }

                $quantities[$line['bookId']] = ($quantities[$line['bookId']] ?? 0) + $line['quantity'];
            }

            if ([] !== $errors) {
                throw new JsonApiException(422, $errors);
            }

            foreach ($quantities as $bookId => $quantity) {
                if ($books[$bookId]->getStock() < $quantity) {
                    throw JsonApiException::single(409, \sprintf('Not enough stock for book %d.', $bookId));
                }
            }

            $total = 0;

            foreach ($lines as $line) {
                $total += $line['quantity'] * $books[$line['bookId']]->getPriceCents();
            }

            try {
                $this->payment->charge($customerId, $total);
            } catch (PaymentDeclinedException) {
                throw JsonApiException::single(402, 'The payment was declined.');
            } catch (PaymentUnavailableException) {
                throw JsonApiException::single(502, 'The payment service failed.');
            }

            $purchase = new Purchase(
                $this->entityManager->getReference(Customer::class, $customerId),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                $total,
            );
            $this->entityManager->persist($purchase);

            foreach ($lines as $line) {
                $book = $books[$line['bookId']];
                $purchase->addLine(new PurchaseLine($purchase, $book, $line['quantity'], $book->getPriceCents()));
                $book->decreaseStock($line['quantity']);
            }

            $this->entityManager->flush();

            return $purchase;
        });

        return $this->mapper->purchase($purchase);
    }

    /**
     * @return list<array{bookId: int, quantity: int}>
     */
    private function validLines(mixed $lines): array
    {
        if (!\is_array($lines) || [] === $lines) {
            throw JsonApiException::single(422, 'At least one line is required.', '/data/attributes/lines');
        }

        $valid = [];
        $errors = [];

        foreach ($lines as $index => $line) {
            $bookId = \is_array($line) ? ($line['bookId'] ?? null) : null;
            $quantity = \is_array($line) ? ($line['quantity'] ?? null) : null;

            if (!\is_int($bookId) || $bookId < 1) {
                $errors[] = JsonApiException::error(422, 'The bookId must be an integer.', \sprintf('/data/attributes/lines/%s/bookId', $index));
            }

            if (!\is_int($quantity) || $quantity < 1) {
                $errors[] = JsonApiException::error(422, 'The quantity must be an integer of at least 1.', \sprintf('/data/attributes/lines/%s/quantity', $index));
            }

            $valid[] = ['bookId' => $bookId, 'quantity' => $quantity];
        }

        if ([] !== $errors) {
            throw new JsonApiException(422, $errors);
        }

        return $valid;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Book> keyed by id
     */
    private function lockBooks(array $ids): array
    {
        sort($ids);
        $books = $this->entityManager->createQuery('SELECT b FROM App\Entity\Book b WHERE b.id IN (:ids) ORDER BY b.id ASC')
            ->setParameter('ids', $ids)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        $byId = [];

        foreach ($books as $book) {
            $byId[$book->getId()] = $book;
        }

        return $byId;
    }
}
