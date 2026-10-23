<?php

namespace App\Payment;

use App\JsonApi\JsonApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the external payment service to accept a payment.
 */
final class PaymentGateway
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(PAYMENT_SERVICE_URL)%')]
        private readonly string $paymentServiceUrl,
    ) {
    }

    /**
     * @throws JsonApiException 402 when declined, 502 when the service fails or answers anything unexpected
     */
    public function authorize(int $customerId, int $amountCents): void
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->paymentServiceUrl, '/').'/payments', [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true);
        } catch (\Throwable) {
            throw JsonApiException::error(502, 'The payment service is unavailable.');
        }

        $answer = \is_array($body) ? ($body['status'] ?? null) : null;
        if (200 === $status && 'accepted' === $answer) {
            return;
        }
        if (200 === $status && 'declined' === $answer) {
            throw JsonApiException::error(402, 'The payment was declined.');
        }

        throw JsonApiException::error(502, 'The payment service failed.');
    }
}
