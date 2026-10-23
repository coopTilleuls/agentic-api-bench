<?php

namespace App\Payment;

use App\JsonApi\JsonApiException;

/**
 * Asks the external payment service to charge a customer.
 */
final class PaymentClient
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * @return bool true when the payment is accepted, false when it is declined
     *
     * @throws JsonApiException 502 when the service is unreachable, fails or answers something unexpected
     */
    public function charge(int $customerId, int $amountCents): bool
    {
        $curl = curl_init(self::URL);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], \JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (false === $body || $code < 200 || $code >= 300) {
            throw JsonApiException::create(502, 'The payment service is unavailable.');
        }

        $answer = json_decode($body, true);
        $status = \is_array($answer) ? ($answer['status'] ?? null) : null;

        return match ($status) {
            'accepted' => true,
            'declined' => false,
            default => throw JsonApiException::create(502, 'The payment service gave an unexpected answer.'),
        };
    }
}
