<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Api\PaymentClient;
use App\Api\ResourceMapper;
use App\ApiResource\PurchaseInput;
use App\ApiResource\PurchaseResource;
use App\Entity\Book;
use App\Entity\Customer;
use App\Entity\Purchase;
use App\Entity\PurchaseLine;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * POST /purchases: checks the lines, locks the books, takes the payment, then stores the purchase
 * and decreases the stock, all in one transaction.
 */
final class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
        private readonly PaymentClient $payments,
        private readonly ResourceMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseResource
    {
        $customerId = (int) $this->requests->getCurrentRequest()->attributes->get('_customer_id');
        $lines = $this->validLines($data instanceof PurchaseInput ? $data->lines : []);

        $purchase = $this->em->wrapInTransaction(fn () => $this->buy($customerId, $lines));

        return $this->mapper->purchase($purchase);
    }

    /**
     * @param list<array{bookId: int, quantity: int}> $lines
     */
    private function buy(int $customerId, array $lines): Purchase
    {
        $quantities = [];
        $firstLine = [];
        foreach ($lines as $index => $line) {
            $quantities[$line['bookId']] = ($quantities[$line['bookId']] ?? 0) + $line['quantity'];
            $firstLine[$line['bookId']] ??= $index;
        }
        ksort($quantities);

        $books = [];
        foreach (array_keys($quantities) as $bookId) {
            $book = $this->em->find(Book::class, $bookId, LockMode::PESSIMISTIC_WRITE);
            if (null === $book || !$book->isPublished()) {
                throw ApiProblem::single(422, sprintf('Book %d is not available.', $bookId), pointer: sprintf('/data/attributes/lines/%d/bookId', $firstLine[$bookId]));
            }
            $books[$bookId] = $book;
        }

        foreach ($quantities as $bookId => $quantity) {
            $available = $books[$bookId]->getStock();
            if ($available < $quantity) {
                throw ApiProblem::single(409, sprintf('Not enough stock for book %d: %d requested, %d available.', $bookId, $quantity, $available));
            }
        }

        $total = 0;
        foreach ($lines as $line) {
            $total += $line['quantity'] * $books[$line['bookId']]->getPriceCents();
        }

        if ('declined' === $this->payments->charge($customerId, $total)) {
            throw ApiProblem::single(402, 'The payment was declined.');
        }

        $purchase = new Purchase(
            $this->em->getReference(Customer::class, $customerId),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $total,
        );
        foreach ($lines as $line) {
            $book = $books[$line['bookId']];
            $purchase->addLine(new PurchaseLine($purchase, $book, $line['quantity'], $book->getPriceCents()));
            $book->setStock($book->getStock() - $line['quantity']);
        }

        $this->em->persist($purchase);
        $this->em->flush();

        return $purchase;
    }

    /**
     * @return list<array{bookId: int, quantity: int}>
     */
    private function validLines(array $raw): array
    {
        if ([] === $raw) {
            throw ApiProblem::single(422, 'lines must not be empty.', pointer: '/data/attributes/lines');
        }

        $lines = [];
        $errors = [];
        foreach ($raw as $index => $line) {
            $base = sprintf('/data/attributes/lines/%s', $index);
            if (!\is_array($line)) {
                $errors[] = ['detail' => 'Each line must be an object with bookId and quantity.', 'source' => ['pointer' => $base]];
                continue;
            }

            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            $valid = true;
            if (!\is_int($bookId) || $bookId < 1) {
                $errors[] = ['detail' => 'bookId must be an integer.', 'source' => ['pointer' => "$base/bookId"]];
                $valid = false;
            }
            if (!\is_int($quantity) || $quantity < 1) {
                $errors[] = ['detail' => 'quantity must be an integer of at least 1.', 'source' => ['pointer' => "$base/quantity"]];
                $valid = false;
            }
            if ($valid) {
                $lines[] = ['bookId' => $bookId, 'quantity' => $quantity];
            }
        }

        if ([] !== $errors) {
            throw new ApiProblem(422, $errors);
        }

        return $lines;
    }
}
