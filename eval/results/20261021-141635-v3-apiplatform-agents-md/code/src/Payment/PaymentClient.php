<?php

namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client of the external payment service.
 */
final class PaymentClient
{
    public function __construct(
        #[Autowire('%env(PAYMENT_SERVICE_URL)%')]
        private readonly string $url,
    ) {
    }

    /**
     * @return 'accepted'|'declined'
     *
     * @throws PaymentFailed when the service is unreachable or does not give a clear answer
     */
    public function charge(int $customerId, int $amountCents): string
    {
        $curl = curl_init($this->url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], \JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);

        $body = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (false === $body) {
            throw new PaymentFailed(\sprintf('The payment service is unreachable: %s', $error));
        }

        $answer = json_decode((string) $body, true);
        $status = \is_array($answer) ? ($answer['status'] ?? null) : null;

        if ($httpStatus >= 200 && $httpStatus < 300 && 'accepted' === $status) {
            return 'accepted';
        }
        if ($httpStatus >= 200 && $httpStatus < 300 && 'declined' === $status) {
            return 'declined';
        }

        throw new PaymentFailed(\sprintf('The payment service answered %d with an unexpected body.', $httpStatus));
    }
}
