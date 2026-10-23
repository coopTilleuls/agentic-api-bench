<?php

namespace App\JsonApi;

/**
 * A failure rendered as a JSON:API error document.
 */
final class JsonApiException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $errors
     * @param array<string, string>      $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $errors,
        public readonly array $headers = [],
    ) {
        parent::__construct($errors[0]['detail'] ?? 'Error', $status);
    }

    public static function error(int $status, string $title, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        $error = ['status' => (string) $status, 'title' => $title, 'detail' => $detail];
        if (null !== $pointer) {
            $error['source'] = ['pointer' => $pointer];
        } elseif (null !== $parameter) {
            $error['source'] = ['parameter' => $parameter];
        }

        return new self($status, [$error]);
    }

    public static function badRequest(string $parameter, string $detail): self
    {
        return self::error(400, 'Bad Request', $detail, parameter: $parameter);
    }

    public static function unauthorized(string $detail): self
    {
        return self::error(401, 'Unauthorized', $detail);
    }

    public static function notFound(string $detail = 'Resource not found.'): self
    {
        return self::error(404, 'Not Found', $detail);
    }

    public static function conflict(string $detail): self
    {
        return self::error(409, 'Conflict', $detail);
    }

    public static function paymentDeclined(): self
    {
        return self::error(402, 'Payment Required', 'The payment was declined.');
    }

    public static function badGateway(): self
    {
        return self::error(502, 'Bad Gateway', 'The payment service failed to answer correctly.');
    }

    /** @param list<array{0: string, 1: string}> $violations pairs of [pointer, detail] */
    public static function validation(array $violations): self
    {
        $errors = [];
        foreach ($violations as [$pointer, $detail]) {
            $errors[] = [
                'status' => '422',
                'title' => 'Unprocessable Entity',
                'detail' => $detail,
                'source' => ['pointer' => $pointer],
            ];
        }

        return new self(422, $errors);
    }
}
