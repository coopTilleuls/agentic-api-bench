<?php

namespace App\Bookshop;

use App\JsonApi\JsonApiException;

/**
 * Client of the external payment service.
 */
final class PaymentClient
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * Returns true when the payment is accepted, false when it is declined.
     *
     * @throws JsonApiException 502 when the service is unreachable, fails or answers anything else
     */
    public function charge(int $customerId, int $amountCents): bool
    {
        $handle = curl_init(self::URL);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ]);

        $body = curl_exec($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body)) {
            throw JsonApiException::badGateway();
        }

        $answer = json_decode($body, true);
        $status = is_array($answer) ? ($answer['status'] ?? null) : null;

        if ('accepted' === $status && $httpCode >= 200 && $httpCode < 300) {
            return true;
        }
        if ('declined' === $status) {
            return false;
        }

        throw JsonApiException::badGateway();
    }
}
