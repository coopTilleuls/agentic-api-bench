<?php

namespace App\Bookshop;

use App\Http\ApiError;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the external payment service to accept or decline a charge.
 */
final class PaymentGateway
{
    private const URL = 'http://127.0.0.1:18090/payments';

    private readonly HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->http = $http ?? HttpClient::create(['timeout' => 5]);
    }

    /**
     * @return bool true when accepted, false when declined
     *
     * @throws ApiError 502 when the service is unreachable or answers anything else
     */
    public function charge(int $customerId, int $amountCents): bool
    {
        try {
            $response = $this->http->request('POST', self::URL, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable) {
            throw new ApiError(502, 'The payment service is unavailable.');
        }

        if ($status < 200 || $status >= 300 || !isset($body['status'])) {
            throw new ApiError(502, 'The payment service failed.');
        }

        return match ($body['status']) {
            'accepted' => true,
            'declined' => false,
            default => throw new ApiError(502, 'The payment service gave an unexpected answer.'),
        };
    }
}
