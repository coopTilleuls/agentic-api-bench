<?php

namespace App\Payment;

use App\JsonApi\JsonApiException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the external payment service to accept or decline a payment.
 */
final class PaymentService
{
    private const URL = 'http://127.0.0.1:18090/payments';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return bool true when the payment is accepted, false when it is declined
     *
     * @throws JsonApiException 502 when the payment service is unreachable or does not answer as expected
     */
    public function charge(int $customerId, int $amountCents): bool
    {
        try {
            $response = $this->httpClient->request('POST', self::URL, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (HttpClientException) {
            throw self::unavailable();
        }

        $answer = json_decode($body, true);
        if (200 !== $status || !\is_array($answer)) {
            throw self::unavailable();
        }

        return match ($answer['status'] ?? null) {
            'accepted' => true,
            'declined' => false,
            default => throw self::unavailable(),
        };
    }

    private static function unavailable(): JsonApiException
    {
        return JsonApiException::single(502, 'Bad Gateway', 'The payment service did not answer as expected.');
    }
}
