<?php

namespace App\Payment;

use App\Http\ApiException;

/**
 * The external payment service: POST /payments answers accepted or declined.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * @return bool true when the payment is accepted, false when it is declined
     *
     * @throws ApiException 502 when the service is unreachable, fails, or answers something unexpected
     */
    public function charge(int $customerId, int $amountCents): bool
    {
        $handle = curl_init(self::URL);
        curl_setopt_array($handle, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], \JSON_THROW_ON_ERROR),
            \CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CONNECTTIMEOUT => 3,
            \CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (false === $body || 200 !== $status) {
            throw new ApiException(502, 'The payment service failed.');
        }
        $answer = json_decode((string) $body, true);
        $result = \is_array($answer) ? ($answer['status'] ?? null) : null;

        return match ($result) {
            'accepted' => true,
            'declined' => false,
            default => throw new ApiException(502, 'The payment service gave an unexpected answer.'),
        };
    }
}
