<?php

namespace App\Bookshop;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client of the external payment service.
 */
final class PaymentGateway
{
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';

    public function __construct(
        #[Autowire(env: 'PAYMENT_SERVICE_URL')]
        private readonly string $url,
    ) {
    }

    /**
     * @return self::ACCEPTED|self::DECLINED
     *
     * @throws \RuntimeException when the service is unreachable or does not answer as expected
     */
    public function charge(int $customerId, int $amountCents): string
    {
        $handle = curl_init($this->url);
        if (false === $handle) {
            throw new \RuntimeException('The payment service cannot be initialised.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);

        $body = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (false === $body || 200 !== $code) {
            throw new \RuntimeException(sprintf('The payment service failed (%s).', '' !== $error ? $error : 'HTTP ' . $code));
        }

        $answer = json_decode((string) $body, true);
        $status = \is_array($answer) ? ($answer['status'] ?? null) : null;

        return match ($status) {
            self::ACCEPTED => self::ACCEPTED,
            self::DECLINED => self::DECLINED,
            default => throw new \RuntimeException('The payment service gave an unexpected answer.'),
        };
    }
}
