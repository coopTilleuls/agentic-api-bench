<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\PurchaseInput;
use App\Entity\Book;
use App\Entity\Customer;
use App\Entity\Purchase;
use App\JsonApi\Query;
use App\EventListener\CustomerIdentificationListener;
use App\Payment\PaymentGateway;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Buys books: checks the lines, locks the books, takes the payment and stores
 * the purchase and the new stock in one transaction. Any failure stores nothing.
 *
 * @implements ProcessorInterface<PurchaseInput, Purchase>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGateway $paymentGateway,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Purchase
    {
        $request = $context['request'];
        $customerId = (int) $request->attributes->get(CustomerIdentificationListener::ATTRIBUTE);
        $lines = $this->validShape($data->lines);

        return $this->entityManager->wrapInTransaction(function () use ($lines, $customerId): Purchase {
            $books = $this->lockBooks($lines);

            $quantities = [];
            foreach ($lines as $line) {
                $quantities[$line['bookId']] = ($quantities[$line['bookId']] ?? 0) + $line['quantity'];
            }
            foreach ($quantities as $bookId => $quantity) {
                if ($books[$bookId]->getStock() < $quantity) {
                    throw new ConflictHttpException(\sprintf('There is not enough stock of book %d.', $bookId));
                }
            }

            $total = 0;
            foreach ($lines as $line) {
                $total += $line['quantity'] * $books[$line['bookId']]->getPriceCents();
            }

            $this->paymentGateway->charge($customerId, $total);

            /** @var Customer $customer */
            $customer = $this->entityManager->getReference(Customer::class, $customerId);
            $purchase = new Purchase($customer, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $total);
            foreach ($lines as $line) {
                $book = $books[$line['bookId']];
                $purchase->addLine($book, $line['quantity']);
                $book->decreaseStock($line['quantity']);
            }

            $this->entityManager->persist($purchase);
            $this->entityManager->flush();

            return $purchase;
        });
    }

    /**
     * @param mixed $lines the lines as sent
     *
     * @return list<array{bookId: int, quantity: int}>
     */
    private function validShape(mixed $lines): array
    {
        $violations = [];
        if (!\is_array($lines) || [] === $lines) {
            $violations[] = $this->violation('lines', 'At least one line is required.');
        } else {
            foreach (array_values($lines) as $index => $line) {
                $path = \sprintf('lines[%d]', $index);
                if (!\is_array($line)) {
                    $violations[] = $this->violation($path, 'Each line must be an object with a bookId and a quantity.');
                    continue;
                }
                if (!\is_int($line['bookId'] ?? null)) {
                    $violations[] = $this->violation($path.'.bookId', 'The bookId must be an integer.');
                }
                if (!\is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                    $violations[] = $this->violation($path.'.quantity', 'The quantity must be an integer of at least 1.');
                }
            }
        }

        if ([] !== $violations) {
            throw new ValidationException(new ConstraintViolationList($violations));
        }

        return array_values($lines);
    }

    /**
     * Locks the books of the lines, which must all exist and be published.
     *
     * @param list<array{bookId: int, quantity: int}> $lines
     *
     * @return array<int, Book> the books by id
     */
    private function lockBooks(array $lines): array
    {
        $books = [];
        $violations = [];
        foreach ($lines as $index => $line) {
            $book = Query::id((string) $line['bookId']) > 0
                ? $this->entityManager->find(Book::class, $line['bookId'], LockMode::PESSIMISTIC_WRITE)
                : null;
            if (null === $book || !$book->isPublished()) {
                $violations[] = $this->violation(\sprintf('lines[%d].bookId', $index), 'The book must exist and be published.');
                continue;
            }
            $books[$line['bookId']] = $book;
        }

        if ([] !== $violations) {
            throw new ValidationException(new ConstraintViolationList($violations));
        }

        return $books;
    }

    private function violation(string $path, string $message): ConstraintViolation
    {
        return new ConstraintViolation($message, null, [], null, $path, null);
    }
}
