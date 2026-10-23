<?php

namespace App\Payment;

/**
 * Client of the external payment service.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * @return 'accepted'|'declined'
     *
     * @throws \RuntimeException when the service is unreachable or does not give a usable answer
     */
    public function charge(int $customerId, int $amountCents): string
    {
        $curl = curl_init(self::URL);
        if (false === $curl) {
            throw new \RuntimeException('Cannot initialise the payment request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);

        if (false === $body) {
            throw new \RuntimeException("Payment service unreachable: $error");
        }

        $answer = json_decode((string) $body, true);
        $status = is_array($answer) ? ($answer['status'] ?? null) : null;

        if ('accepted' === $status && $code >= 200 && $code < 300) {
            return 'accepted';
        }
        if ('declined' === $status) {
            return 'declined';
        }

        throw new \RuntimeException("Unexpected answer from the payment service: HTTP $code $body");
    }
}
