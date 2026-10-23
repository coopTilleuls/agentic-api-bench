<?php

namespace App\Payment;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the external payment service to accept a payment.
 */
final class PaymentClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $url = 'http://127.0.0.1:18090/payments',
    ) {
    }

    /**
     * @throws PaymentDeclinedException when the service declines the payment
     * @throws PaymentUnavailableException when the service is unreachable or answers anything else
     */
    public function charge(int $customerId, int $amountCents): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->url, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface) {
            throw new PaymentUnavailableException();
        }

        if ($status >= 200 && $status < 300 && \is_array($body)) {
            if ('accepted' === ($body['status'] ?? null)) {
                return;
            }

            if ('declined' === ($body['status'] ?? null)) {
                throw new PaymentDeclinedException();
            }
        }

        throw new PaymentUnavailableException();
    }
}
