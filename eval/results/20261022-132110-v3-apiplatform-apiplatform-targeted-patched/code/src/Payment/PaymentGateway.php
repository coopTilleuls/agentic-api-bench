<?php

declare(strict_types=1);

namespace App\Payment;

use App\Api\ApiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the external payment service to accept or decline a payment.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @throws ApiException 402 when the payment is declined, 502 when the service fails or answers anything else
     */
    public function charge(int $customerId, int $amountCents): void
    {
        try {
            $response = $this->httpClient->request('POST', self::URL, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true);
        } catch (TransportExceptionInterface) {
            throw ApiException::status(502, 'The payment service is unavailable.');
        }

        $result = \is_array($body) ? ($body['status'] ?? null) : null;

        if ('accepted' === $result && $statusCode >= 200 && $statusCode < 300) {
            return;
        }
        if ('declined' === $result) {
            throw ApiException::status(402, 'The payment was declined.');
        }

        throw ApiException::status(502, 'The payment service failed.');
    }
}
