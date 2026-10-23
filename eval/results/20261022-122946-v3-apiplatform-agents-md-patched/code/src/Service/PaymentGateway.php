<?php

namespace App\Service;

use App\Http\JsonApiException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the external payment service to accept or decline a payment.
 */
final class PaymentGateway
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $url = 'http://127.0.0.1:18090/payments',
    ) {
    }

    /**
     * @throws JsonApiException 402 when the payment is declined, 502 when the service is unreachable or answers anything else
     */
    public function charge(int $customerId, int $amountCents): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->url, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpClientException) {
            throw $this->unavailable();
        }

        $body = json_decode($content, true);
        if (!\is_array($body) || !\in_array($status, [200, 201], true)) {
            throw $this->unavailable();
        }

        match ($body['status'] ?? null) {
            'accepted' => null,
            'declined' => throw JsonApiException::single(402, 'Payment declined', 'The payment service declined the payment.'),
            default => throw $this->unavailable(),
        };
    }

    private function unavailable(): JsonApiException
    {
        return JsonApiException::single(502, 'Payment failed', 'The payment service did not accept the payment request.');
    }
}
