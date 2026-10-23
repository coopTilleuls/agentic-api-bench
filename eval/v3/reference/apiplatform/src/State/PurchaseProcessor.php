<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\PurchaseInput;
use App\Entity\Book;
use App\Entity\Customer;
use App\Entity\Purchase;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
        private Security $security,
        #[Autowire(env: 'PAYMENT_URL')]
        private string $paymentUrl,
    ) {
    }

    /** @param PurchaseInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Purchase
    {
        $customer = $this->security->getUser();
        \assert($customer instanceof Customer);

        $quantities = [];
        foreach ($data->lines as $line) {
            $quantities[$line['bookId']] = ($quantities[$line['bookId']] ?? 0) + $line['quantity'];
        }
        ksort($quantities);

        return $this->entityManager->wrapInTransaction(function () use ($customer, $data, $quantities): Purchase {
            $books = [];
            foreach ($quantities as $bookId => $quantity) {
                $book = $this->entityManager->find(Book::class, $bookId, LockMode::PESSIMISTIC_WRITE);
                if (null === $book || $book->getStock() < $quantity) {
                    throw new ConflictHttpException(\sprintf('Not enough stock for book %d.', $bookId));
                }
                $books[$bookId] = $book;
            }

            $total = 0;
            foreach ($data->lines as $line) {
                $total += $line['quantity'] * $books[$line['bookId']]->getPriceCents();
            }

            $this->pay($customer, $total);

            $purchase = new Purchase($customer, $total);
            foreach ($data->lines as $line) {
                $book = $books[$line['bookId']];
                $purchase->addLine($book, $line['quantity'], $book->getPriceCents());
            }
            foreach ($quantities as $bookId => $quantity) {
                $books[$bookId]->setStock($books[$bookId]->getStock() - $quantity);
            }
            $this->entityManager->persist($purchase);

            return $purchase;
        });
    }

    private function pay(Customer $customer, int $amountCents): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->paymentUrl, [
                'json' => ['customerId' => $customer->getId(), 'amountCents' => $amountCents],
                'timeout' => 5,
            ]);
            $status = 200 === $response->getStatusCode() ? ($response->toArray()['status'] ?? null) : null;
        } catch (ExceptionInterface) {
            $status = null;
        }

        match ($status) {
            'accepted' => null,
            'declined' => throw new HttpException(Response::HTTP_PAYMENT_REQUIRED, 'Payment declined.'),
            default => throw new HttpException(Response::HTTP_BAD_GATEWAY, 'Payment service unavailable.'),
        };
    }
}
