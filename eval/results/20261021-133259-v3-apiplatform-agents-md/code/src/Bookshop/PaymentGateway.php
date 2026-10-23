<?php

namespace App\Bookshop;

use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;

/**
 * Client of the external payment service.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * @return bool true when accepted, false when declined
     *
     * @throws JsonApiException 502 when the service is unreachable or answers anything else
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
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = false === $body || 0 !== curl_errno($handle);
        curl_close($handle);

        if ($failed || 200 !== $status) {
            throw self::unavailable();
        }

        $decoded = json_decode((string) $body, true);
        $result = is_array($decoded) ? ($decoded['status'] ?? null) : null;

        return match ($result) {
            'accepted' => true,
            'declined' => false,
            default => throw self::unavailable(),
        };
    }

    private static function unavailable(): JsonApiException
    {
        return JsonApiException::one(502, 'Bad Gateway', 'The payment service failed to answer correctly.');
    }
}
