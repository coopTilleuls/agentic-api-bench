<?php

namespace App\Payment;

use App\JsonApi\ApiException;

/**
 * Client of the external payment service.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * @throws ApiException 402 when declined, 502 when the service fails or answers anything else
     */
    public function charge(int $customerId, int $amountCents): void
    {
        $payload = json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], JSON_THROW_ON_ERROR);

        $curl = curl_init(self::URL);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $failed = false !== $body ? curl_errno($curl) : true;
        curl_close($curl);

        $answer = (!$failed && 200 === $httpCode && \is_string($body)) ? json_decode($body, true) : null;
        $status = \is_array($answer) ? ($answer['status'] ?? null) : null;

        if ('accepted' === $status) {
            return;
        }
        if ('declined' === $status) {
            throw ApiException::status(402, 'The payment was declined.');
        }

        throw ApiException::status(502, 'The payment service is unavailable.');
    }
}
