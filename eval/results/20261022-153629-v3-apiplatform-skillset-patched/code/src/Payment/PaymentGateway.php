<?php

namespace App\Payment;

use App\Http\JsonApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The external payment service. Declined answers 402; unreachable, failing or unexpected answers 502.
 */
final class PaymentGateway
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%app.payment_url%')]
        private readonly string $url,
    ) {
    }

    public function charge(int $customerId, int $amountCents): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->url, [
                'json' => ['customerId' => $customerId, 'amountCents' => $amountCents],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true);
        } catch (HttpClientException) {
            throw JsonApiException::create(502, 'The payment service is unavailable.');
        }

        $answer = \is_array($body) ? ($body['status'] ?? null) : null;
        if ('accepted' === $answer && $status >= 200 && $status < 300) {
            return;
        }
        if ('declined' === $answer) {
            throw JsonApiException::create(402, 'The payment was declined.');
        }

        throw JsonApiException::create(502, 'The payment service gave an unexpected answer.');
    }
}
