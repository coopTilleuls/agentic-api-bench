<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PurchaseInput;
use App\Customer\CurrentCustomer;
use App\Entity\Book;
use App\Entity\Customer;
use App\Entity\Purchase;
use App\Entity\PurchaseLine;
use App\Exception\JsonApiException;
use App\Payment\PaymentFailedException;
use App\Payment\PaymentGateway;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Buys books: validates the lines, locks the books, checks stock, takes the payment and stores the purchase.
 * Everything happens in one transaction, so a failure stores nothing and changes no stock.
 */
final class PurchaseProcessor implements ProcessorInterface
{
    /** The largest id a database integer column holds. */
    private const MAX_ID = 2147483647;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CurrentCustomer $currentCustomer,
        private readonly PaymentGateway $paymentGateway,
    ) {
    }

    /**
     * @param PurchaseInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Purchase
    {
        $lines = $this->validateLines($data->lines);
        $customerId = $this->currentCustomer->id();

        $quantityByBook = [];
        foreach ($lines as $line) {
            $quantityByBook[$line['bookId']] = ($quantityByBook[$line['bookId']] ?? 0) + $line['quantity'];
        }
        ksort($quantityByBook);

        return $this->entityManager->wrapInTransaction(function () use ($lines, $quantityByBook, $customerId): Purchase {
            $books = [];
            foreach ($quantityByBook as $bookId => $quantity) {
                $books[$bookId] = $this->entityManager->find(Book::class, $bookId, LockMode::PESSIMISTIC_WRITE);
            }

            $errors = [];
            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']];
                if (null === $book || !$book->isPublished()) {
                    $errors[] = ['detail' => 'The book does not exist or is not published.', 'source' => ['pointer' => \sprintf('/data/attributes/lines/%d/bookId', $index)]];
                }
            }
            if ($errors) {
                throw new JsonApiException(422, $errors);
            }

            foreach ($quantityByBook as $bookId => $quantity) {
                if ($books[$bookId]->getStock() < $quantity) {
                    throw JsonApiException::one(409, \sprintf('Not enough stock for book %d: %d asked, %d available.', $bookId, $quantity, $books[$bookId]->getStock()));
                }
            }

            $purchase = new Purchase(
                $this->entityManager->getReference(Customer::class, $customerId),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            $totalCents = 0;
            foreach ($lines as $line) {
                $book = $books[$line['bookId']];
                new PurchaseLine($purchase, $book, $line['quantity'], $book->getPriceCents());
                $totalCents += $line['quantity'] * $book->getPriceCents();
            }
            $purchase->setTotalCents($totalCents);

            try {
                $accepted = $this->paymentGateway->charge($customerId, $totalCents);
            } catch (PaymentFailedException $e) {
                throw JsonApiException::one(502, 'The payment service is unavailable or gave an invalid answer.');
            }
            if (!$accepted) {
                throw JsonApiException::one(402, 'The payment was declined.');
            }

            foreach ($quantityByBook as $bookId => $quantity) {
                $books[$bookId]->decreaseStock($quantity);
            }

            $this->entityManager->persist($purchase);
            $this->entityManager->flush();

            return $purchase;
        });
    }

    /**
     * @return list<array{bookId: int, quantity: int}>
     */
    private function validateLines(mixed $lines): array
    {
        if (!\is_array($lines) || [] === $lines || !array_is_list($lines)) {
            throw JsonApiException::one(422, 'The purchase must have at least one line.', ['pointer' => '/data/attributes/lines']);
        }

        $errors = [];
        $valid = [];
        foreach ($lines as $index => $line) {
            $bookId = \is_array($line) ? ($line['bookId'] ?? null) : null;
            $quantity = \is_array($line) ? ($line['quantity'] ?? null) : null;

            if (!\is_int($bookId) || $bookId < 1 || $bookId > self::MAX_ID) {
                $errors[] = ['detail' => 'The bookId must be an integer.', 'source' => ['pointer' => \sprintf('/data/attributes/lines/%d/bookId', $index)]];
            }
            if (!\is_int($quantity) || $quantity < 1) {
                $errors[] = ['detail' => 'The quantity must be an integer of at least 1.', 'source' => ['pointer' => \sprintf('/data/attributes/lines/%d/quantity', $index)]];
            }
            if (\is_int($bookId) && \is_int($quantity) && $bookId >= 1 && $bookId <= self::MAX_ID && $quantity >= 1) {
                $valid[] = ['bookId' => $bookId, 'quantity' => $quantity];
            }
        }

        if ($errors) {
            throw new JsonApiException(422, $errors);
        }

        return $valid;
    }
}
