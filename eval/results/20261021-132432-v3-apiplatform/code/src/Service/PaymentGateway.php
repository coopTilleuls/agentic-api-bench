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
     * @return bool true when the payment is accepted, false when it is declined
     *
     * @throws ApiException 502 when the service is unreachable or does not give a usable answer
     */
    public function pay(int $customerId, int $amountCents): bool
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
        $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $answer = is_string($body) ? json_decode($body, true) : null;
        if (200 !== $code || !is_array($answer) || !isset($answer['status'])) {
            throw ApiException::error(502, 'The payment service is unavailable.');
        }

        return match ($answer['status']) {
            'accepted' => true,
            'declined' => false,
            default => throw ApiException::error(502, 'The payment service gave an unknown answer.'),
        };
    }
}
