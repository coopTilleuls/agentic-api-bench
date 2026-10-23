<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PurchaseResource;
use App\Customer\CurrentCustomer;
use App\Entity\Book;
use App\Entity\Customer;
use App\Entity\Purchase;
use App\Entity\PurchaseLine;
use App\JsonApi\JsonApiException;
use App\JsonApi\RequestDocument;
use App\Payment\PaymentGateway;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Buys books: checks stock, takes the payment, then stores the purchase and decreases the stock.
 *
 * @implements ProcessorInterface<null, Response>
 */
final class PurchaseCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly CurrentCustomer $currentCustomer,
        private readonly PaymentGateway $payments,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $customer = $this->currentCustomer->get();
        $lines = $this->lines(RequestDocument::data($this->requestStack->getCurrentRequest()));

        $purchase = $this->em->wrapInTransaction(fn (): PurchaseResource => $this->buy($customer, $lines));

        return new Response($this->serializer->serialize($purchase, 'json_api'), 201, [
            'Content-Type' => 'application/vnd.api+json',
            'Location' => '/purchases/'.$purchase->id,
        ]);
    }

    /**
     * @return list<array{bookId: int, quantity: int, pointer: string}>
     */
    private function lines(array $data): array
    {
        $attributes = $data['attributes'] ?? null;
        $lines = \is_array($attributes) ? ($attributes['lines'] ?? null) : null;
        if (!\is_array($lines) || [] === $lines || !array_is_list($lines)) {
            throw JsonApiException::validation([['pointer' => '/data/attributes/lines', 'detail' => 'The lines must be a non-empty list.']]);
        }

        $violations = [];
        $result = [];
        foreach ($lines as $index => $line) {
            $pointer = sprintf('/data/attributes/lines/%d', $index);
            if (!\is_array($line) || [] === $line || array_is_list($line)) {
                $violations[] = ['pointer' => $pointer, 'detail' => 'A line must be an object.'];
                continue;
            }
            $valid = true;
            if (!\is_int($line['bookId'] ?? null)) {
                $violations[] = ['pointer' => $pointer.'/bookId', 'detail' => 'The bookId must be an integer.'];
                $valid = false;
            }
            if (!\is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                $violations[] = ['pointer' => $pointer.'/quantity', 'detail' => 'The quantity must be an integer greater than or equal to 1.'];
                $valid = false;
            }
            if ($valid) {
                $result[] = ['bookId' => $line['bookId'], 'quantity' => $line['quantity'], 'pointer' => $pointer];
            }
        }
        if ([] !== $violations) {
            throw JsonApiException::validation($violations);
        }

        return $result;
    }

    /**
     * @param list<array{bookId: int, quantity: int, pointer: string}> $lines
     */
    private function buy(Customer $customer, array $lines): PurchaseResource
    {
        // Lock the books in id order so concurrent purchases cannot deadlock or oversell.
        $ids = array_unique(array_column($lines, 'bookId'));
        sort($ids);
        $books = [];
        foreach ($ids as $id) {
            $books[$id] = $this->em->find(Book::class, $id, LockMode::PESSIMISTIC_WRITE);
        }

        $violations = [];
        foreach ($lines as $line) {
            $book = $books[$line['bookId']];
            if (null === $book || !$book->isPublished()) {
                $violations[] = ['pointer' => $line['pointer'].'/bookId', 'detail' => 'The book is not available.'];
            }
        }
        if ([] !== $violations) {
            throw JsonApiException::validation($violations);
        }

        $requested = [];
        $total = 0;
        foreach ($lines as $line) {
            $requested[$line['bookId']] = ($requested[$line['bookId']] ?? 0) + $line['quantity'];
            $total += $line['quantity'] * $books[$line['bookId']]->getPriceCents();
        }
        foreach ($requested as $id => $quantity) {
            if ($books[$id]->getStock() < $quantity) {
                throw JsonApiException::error(409, sprintf('Not enough stock for book %d.', $id), ['pointer' => '/data/attributes/lines']);
            }
        }

        $this->payments->authorize((int) $customer->getId(), $total);

        $purchase = new Purchase($customer, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $total);
        $this->em->persist($purchase);

        $views = [];
        foreach ($lines as $line) {
            $book = $books[$line['bookId']];
            $unitPrice = $book->getPriceCents();
            $this->em->persist(new PurchaseLine($purchase, $book, $line['quantity'], $unitPrice));
            $book->setStock($book->getStock() - $line['quantity']);
            $views[] = ['bookId' => (int) $book->getId(), 'title' => $book->getTitle(), 'quantity' => $line['quantity'], 'unitPriceCents' => $unitPrice];
        }
        $this->em->flush();

        return PurchaseResource::create((int) $purchase->getId(), $purchase->getCreatedAt(), $total, $views);
    }
}
