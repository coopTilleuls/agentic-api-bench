<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiException;
use App\Api\CustomerIdListener;
use App\Api\JsonApiBody;
use App\Entity\Book;
use App\Entity\Customer;
use App\Entity\Purchase;
use App\Entity\PurchaseLine;
use App\Payment\PaymentGateway;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Buys books: validates the lines, checks and locks the stock, takes the payment, then stores the purchase.
 */
final class PurchaseCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGateway $payments,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Purchase
    {
        /** @var Request $request */
        $request = $context['request'];
        $customerId = (int) $request->attributes->get(CustomerIdListener::ATTRIBUTE);
        $lines = $this->lines(JsonApiBody::data($request));

        return $this->entityManager->wrapInTransaction(function () use ($customerId, $lines): Purchase {
            $bookIds = array_values(array_unique(array_column($lines, 'bookId')));
            sort($bookIds);

            $books = [];
            foreach ($bookIds as $bookId) {
                $books[$bookId] = $this->entityManager->find(Book::class, $bookId, LockMode::PESSIMISTIC_WRITE);
            }

            $problems = [];
            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']];
                if (null === $book || !$book->isPublished()) {
                    $problems[] = ['detail' => \sprintf('Book %d is not available.', $line['bookId']), 'pointer' => \sprintf('/data/attributes/lines/%d/bookId', $index), 'parameter' => null];
                }
            }
            if ([] !== $problems) {
                throw ApiException::problems(422, $problems);
            }

            $needed = [];
            foreach ($lines as $line) {
                $needed[$line['bookId']] = ($needed[$line['bookId']] ?? 0) + $line['quantity'];
            }
            foreach ($needed as $bookId => $quantity) {
                if ($books[$bookId]->getStock() < $quantity) {
                    throw ApiException::status(409, \sprintf('Not enough stock for book %d.', $bookId));
                }
            }

            $total = 0;
            foreach ($lines as $line) {
                $total += $line['quantity'] * $books[$line['bookId']]->getPriceCents();
            }

            $this->payments->charge($customerId, $total);

            $purchase = new Purchase(
                $this->entityManager->getReference(Customer::class, $customerId),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                $total,
            );
            foreach ($lines as $line) {
                $book = $books[$line['bookId']];
                new PurchaseLine($purchase, $book, $line['quantity'], $book->getPriceCents());
                $book->setStock($book->getStock() - $line['quantity']);
            }

            $this->entityManager->persist($purchase);
            $this->entityManager->flush();

            return $purchase;
        });
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{bookId: int, quantity: int}>
     */
    private function lines(array $data): array
    {
        $attributes = $data['attributes'] ?? [];
        $lines = \is_array($attributes) ? ($attributes['lines'] ?? null) : null;

        if (!\is_array($lines) || [] === $lines || !array_is_list($lines)) {
            throw ApiException::problems(422, [['detail' => 'lines must be a non-empty list.', 'pointer' => '/data/attributes/lines', 'parameter' => null]]);
        }

        $problems = [];
        $valid = [];
        foreach ($lines as $index => $line) {
            $base = \sprintf('/data/attributes/lines/%d', $index);
            if (!\is_array($line) || array_is_list($line)) {
                $problems[] = ['detail' => 'Each line must be an object.', 'pointer' => $base, 'parameter' => null];
                continue;
            }

            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (!\is_int($bookId)) {
                $problems[] = ['detail' => 'bookId must be an integer.', 'pointer' => $base.'/bookId', 'parameter' => null];
            }
            if (!\is_int($quantity) || $quantity < 1) {
                $problems[] = ['detail' => 'quantity must be an integer of at least 1.', 'pointer' => $base.'/quantity', 'parameter' => null];
            }
            if (\is_int($bookId) && \is_int($quantity) && $quantity >= 1) {
                $valid[] = ['bookId' => $bookId, 'quantity' => $quantity];
            }
        }

        if ([] !== $problems) {
            throw ApiException::problems(422, $problems);
        }

        return $valid;
    }
}
