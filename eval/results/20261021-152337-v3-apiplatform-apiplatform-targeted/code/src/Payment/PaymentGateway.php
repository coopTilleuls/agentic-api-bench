<?php

namespace App\Payment;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Asks the external payment service to take a payment.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @throws HttpException 402 when the payment is declined, 502 when the service fails or answers oddly
     */
    public function charge(int $customerId, int $amountCents): void
    {
        try {
            $response = $this->httpClient->request('POST', self::URL, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            $answer = $response->toArray(false);
        } catch (\Throwable) {
            throw new HttpException(502, 'The payment service could not be reached or failed.');
        }

        $outcome = \is_array($answer) ? ($answer['status'] ?? null) : null;

        if ('accepted' === $outcome && $status >= 200 && $status < 300) {
            return;
        }
        if ('declined' === $outcome && (($status >= 200 && $status < 300) || 402 === $status)) {
            throw new HttpException(402, 'The payment was declined.');
        }

        throw new HttpException(502, 'The payment service gave an unexpected answer.');
    }
}
