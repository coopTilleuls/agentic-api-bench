<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/**
 * Asks the external payment service to accept or decline a payment.
 */
final class PaymentClient
{
    private const URL = 'http://127.0.0.1:18090/payments';

    /**
     * @return 'accepted'|'declined'
     *
     * @throws ApiProblem 502 when the service is unreachable or answers anything else
     */
    public function charge(int $customerId, int $amountCents): string
    {
        $body = json_encode(['customerId' => $customerId, 'amountCents' => $amountCents], \JSON_THROW_ON_ERROR);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents(self::URL, false, $context);
        if (false === $response) {
            throw ApiProblem::single(502, 'The payment service is unreachable.');
        }

        $status = $this->statusCode($http_response_header ?? []);
        $answer = json_decode($response, true);
        $result = \is_array($answer) ? ($answer['status'] ?? null) : null;

        if ($status < Response::HTTP_OK || $status >= Response::HTTP_MULTIPLE_CHOICES) {
            throw ApiProblem::single(502, 'The payment service failed.');
        }

        return match ($result) {
            'accepted' => 'accepted',
            'declined' => 'declined',
            default => throw ApiProblem::single(502, 'The payment service gave an unexpected answer.'),
        };
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }
}
