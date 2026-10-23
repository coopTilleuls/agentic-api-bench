<?php

namespace App\Service;

use App\Http\ApiException;

/**
 * Client of the external payment service.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * @throws ApiException 402 when the payment is declined, 502 when the service fails
     */
    public function charge(int $customerId, int $amountCents): void
    {
        $handle = curl_init(self::URL);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if (false === $body) {
            throw ApiException::one(502, 'The payment service is unreachable.');
        }

        $decoded = json_decode((string) $body, true);
        $status = is_array($decoded) ? ($decoded['status'] ?? null) : null;

        if ('declined' === $status) {
            throw ApiException::one(402, 'The payment was declined.');
        }
        if ('accepted' !== $status || $code < 200 || $code >= 300) {
            throw ApiException::one(502, 'The payment service failed.');
        }
    }
}
