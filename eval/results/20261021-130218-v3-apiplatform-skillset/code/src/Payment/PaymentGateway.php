<?php

namespace App\Payment;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of the external payment service.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return bool true when the payment is accepted, false when it is declined
     *
     * @throws PaymentUnavailableException when the service is unreachable or answers anything else
     */
    public function charge(int $customerId, int $amountCents): bool
    {
        try {
            $response = $this->httpClient->request('POST', self::URL, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable $exception) {
            throw new PaymentUnavailableException('The payment service is unavailable.', 0, $exception);
        }

        $answer = is_array($body) ? ($body['status'] ?? null) : null;
        if ('accepted' === $answer && $status >= 200 && $status < 300) {
            return true;
        }
        if ('declined' === $answer && $status < 500) {
            return false;
        }

        throw new PaymentUnavailableException(sprintf('Unexpected answer from the payment service (HTTP %d).', $status));
    }
}
