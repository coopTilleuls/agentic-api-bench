<?php

namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the external payment service whether a payment is accepted.
 */
final class PaymentGateway
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%app.payment_url%')]
        private readonly string $url,
    ) {
    }

    /**
     * @return bool true when accepted, false when declined
     *
     * @throws PaymentFailedException when the service is unreachable or does not give a valid answer
     */
    public function charge(int $customerId, int $amountCents): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->url, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new PaymentFailedException(\sprintf('Payment service answered %d.', $status));
            }

            $body = $response->toArray(false);
        } catch (PaymentFailedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentFailedException('Payment service failed.', 0, $e);
        }

        return match ($body['status'] ?? null) {
            'accepted' => true,
            'declined' => false,
            default => throw new PaymentFailedException('Payment service gave an unexpected answer.'),
        };
    }
}
