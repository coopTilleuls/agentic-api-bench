<?php

namespace App\Bookshop;

use App\JsonApi\JsonApiException;

/**
 * Client of the external payment service.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * @throws JsonApiException 402 when the payment is declined, 502 when the service fails
     */
    public function charge(int $customerId, int $amountCents): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], JSON_THROW_ON_ERROR),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents(self::URL, false, $context);
        $httpStatus = $this->httpStatus($http_response_header ?? []);
        $answer = false === $body ? null : json_decode($body, true);
        $status = is_array($answer) ? ($answer['status'] ?? null) : null;

        if ('accepted' === $status && $httpStatus >= 200 && $httpStatus < 300) {
            return;
        }
        if ('declined' === $status) {
            throw JsonApiException::create(402, 'Payment declined', 'The payment service declined the payment.');
        }

        throw JsonApiException::create(502, 'Payment failed', 'The payment service did not accept the payment request.');
    }

    /**
     * @param list<string> $headers
     */
    private function httpStatus(array $headers): int
    {
        if (isset($headers[0]) && 1 === preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
