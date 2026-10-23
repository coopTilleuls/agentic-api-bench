<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Response;

/**
 * A request failure, rendered as a JSON:API error document.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $errors
     * @param array<string, string>      $headers
     */
    public function __construct(
        private readonly array $errors,
        private readonly array $headers = [],
    ) {
        parent::__construct((string) ($errors[0]['detail'] ?? 'Error'));
    }

    public static function status(int $status, string $detail, array $headers = []): self
    {
        return new self([self::error($status, $detail)], $headers);
    }

    public static function parameter(string $name, string $detail): self
    {
        return new self([self::error(400, $detail, ['parameter' => $name])]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function statusCode(): int
    {
        return (int) $this->errors[0]['status'];
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @param array<string, string> $source
     *
     * @return array<string, mixed>
     */
    public static function error(int $status, string $detail, array $source = []): array
    {
        $error = [
            'status' => (string) $status,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'detail' => $detail,
        ];
        if ([] !== $source) {
            $error['source'] = $source;
        }

        return $error;
    }
}
