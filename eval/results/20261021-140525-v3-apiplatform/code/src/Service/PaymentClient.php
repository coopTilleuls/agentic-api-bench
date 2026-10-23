<?php

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the external payment service whether a payment is accepted.
 */
final class PaymentClient
{
    private const URL = 'http://127.0.0.1:18090/payments';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @throws ApiException 402 when declined, 502 when the service fails or answers anything else
     */
    public function charge(int $customerId, int $amountCents): void
    {
        try {
            $response = $this->httpClient->request('POST', self::URL, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
            ]);
            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            throw $this->badGateway();
        }

        $answer = json_decode($body, true);
        $status = \is_array($answer) ? ($answer['status'] ?? null) : null;

        if (200 !== $statusCode) {
            throw $this->badGateway();
        }
        if ('accepted' === $status) {
            return;
        }
        if ('declined' === $status) {
            throw ApiException::single(402, 'Payment Required', 'The payment was declined.');
        }

        throw $this->badGateway();
    }

    private function badGateway(): ApiException
    {
        return ApiException::single(502, 'Bad Gateway', 'The payment service failed.');
    }
}
