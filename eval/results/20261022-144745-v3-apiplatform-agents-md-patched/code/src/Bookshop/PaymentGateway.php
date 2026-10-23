<?php

namespace App\Bookshop;

/**
 * Client of the external payment service.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * @return bool true when the payment is accepted, false when it is declined
     *
     * @throws PaymentUnavailable when the service cannot be reached or does not give a usable answer
     */
    public function charge(int $customerId, int $amountCents): bool
    {
        $curl = curl_init(self::URL);
        if (false === $curl) {
            throw new PaymentUnavailable('The payment service cannot be called.');
        }

        try {
            curl_setopt_array($curl, [
                \CURLOPT_POST => true,
                \CURLOPT_POSTFIELDS => json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], \JSON_THROW_ON_ERROR),
                \CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_CONNECTTIMEOUT => 3,
                \CURLOPT_TIMEOUT => 10,
            ]);

            $body = curl_exec($curl);
            $code = (int) curl_getinfo($curl, \CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }

        if (!\is_string($body)) {
            throw new PaymentUnavailable('The payment service is unreachable.');
        }

        try {
            $answer = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentUnavailable('The payment service gave an invalid answer.');
        }

        $status = \is_array($answer) ? ($answer['status'] ?? null) : null;

        if ('declined' === $status) {
            return false;
        }
        if ('accepted' === $status && $code >= 200 && $code < 300) {
            return true;
        }

        throw new PaymentUnavailable('The payment service failed.');
    }
}
