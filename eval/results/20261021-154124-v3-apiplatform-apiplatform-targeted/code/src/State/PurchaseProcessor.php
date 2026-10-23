<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\Book;
use App\Entity\Customer;
use App\Entity\Purchase;
use App\Entity\PurchaseLine;
use App\Input\PurchaseInput;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadGatewayHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Buys books: validates the lines, locks the books, checks the stock, takes the
 * payment, then stores the purchase and decreases the stock. Any failure rolls
 * everything back.
 *
 * @implements ProcessorInterface<PurchaseInput, Purchase>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    private const PAYMENT_URL = 'http://127.0.0.1:18090/payments';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Purchase
    {
        if (!$operation instanceof Post) {
            throw new \LogicException('PurchaseProcessor only handles POST.');
        }

        $customerId = (int) $this->requestStack->getCurrentRequest()->attributes->get('_customer_id');
        $customer = $this->entityManager->getReference(Customer::class, $customerId);
        $lines = $data->lines;

        return $this->entityManager->wrapInTransaction(function () use ($customer, $customerId, $lines): Purchase {
            $violations = new ConstraintViolationList();

            if (!\is_array($lines) || [] === $lines) {
                throw new ValidationException(new ConstraintViolationList([
                    new ConstraintViolation('Lines must not be empty.', null, [], null, 'lines', $lines),
                ]));
            }

            $requested = [];
            foreach ($lines as $index => $line) {
                if (!\is_array($line) || !\is_int($line['bookId'] ?? null)) {
                    $violations->add(new ConstraintViolation('The bookId must be an integer.', null, [], null, "lines[$index].bookId", $line['bookId'] ?? null));
                    continue;
                }
                if (!\is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                    $violations->add(new ConstraintViolation('The quantity must be an integer of at least 1.', null, [], null, "lines[$index].quantity", $line['quantity'] ?? null));
                    continue;
                }
                $requested[$line['bookId']] = ($requested[$line['bookId']] ?? 0) + $line['quantity'];
            }

            if (\count($violations) > 0) {
                throw new ValidationException($violations);
            }

            // Locked in id order so that concurrent purchases cannot deadlock.
            ksort($requested);
            $books = [];
            foreach (array_keys($requested) as $bookId) {
                $book = $this->entityManager->find(Book::class, $bookId, LockMode::PESSIMISTIC_WRITE);
                if (null !== $book && $book->isPublished()) {
                    $books[$bookId] = $book;
                }
            }

            foreach ($lines as $index => $line) {
                if (!isset($books[$line['bookId']])) {
                    $violations->add(new ConstraintViolation('The book is not available.', null, [], null, "lines[$index].bookId", $line['bookId']));
                }
            }

            if (\count($violations) > 0) {
                throw new ValidationException($violations);
            }

            foreach ($requested as $bookId => $quantity) {
                if ($books[$bookId]->getStock() < $quantity) {
                    throw new ConflictHttpException(\sprintf('Not enough stock for book %d.', $bookId));
                }
            }

            $total = 0;
            foreach ($lines as $line) {
                $total += $line['quantity'] * $books[$line['bookId']]->getPriceCents();
            }

            $this->pay($customerId, $total);

            $purchase = new Purchase($customer, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $total);
            $this->entityManager->persist($purchase);
            foreach ($lines as $line) {
                $book = $books[$line['bookId']];
                $purchase->addLine(new PurchaseLine($purchase, $book, $line['quantity'], $book->getPriceCents()));
            }
            foreach ($requested as $bookId => $quantity) {
                $books[$bookId]->setStock($books[$bookId]->getStock() - $quantity);
            }
            $this->entityManager->flush();

            return $purchase;
        });
    }

    private function pay(int $customerId, int $amountCents): void
    {
        try {
            $response = $this->httpClient->request('POST', self::PAYMENT_URL, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            $answer = $response->toArray(false);
        } catch (TransportException $e) {
            throw new BadGatewayHttpException('The payment service is unreachable.', $e);
        } catch (\Throwable $e) {
            throw new BadGatewayHttpException('The payment service failed.', $e);
        }

        if (200 !== $status) {
            throw new BadGatewayHttpException('The payment service failed.');
        }

        match ($answer['status'] ?? null) {
            'accepted' => null,
            'declined' => throw new HttpException(402, 'The payment was declined.'),
            default => throw new BadGatewayHttpException('The payment service gave an unexpected answer.'),
        };
    }
}
