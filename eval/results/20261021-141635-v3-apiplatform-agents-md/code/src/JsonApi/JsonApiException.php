<?php

namespace App\JsonApi;

/**
 * An error that is rendered as a JSON:API error document.
 */
final class JsonApiException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(
        private readonly int $status,
        private readonly array $errors,
    ) {
        parent::__construct($errors[0]['detail'] ?? 'Error');
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * A bad query parameter (400).
     */
    public static function parameter(string $parameter, string $detail): self
    {
        return new self(400, [self::error(400, 'Invalid query parameter', $detail, ['parameter' => $parameter])]);
    }

    /**
     * A bad field in a request body (422).
     */
    public static function invalid(string $pointer, string $detail): self
    {
        return new self(422, [self::error(422, 'Unprocessable Entity', $detail, ['pointer' => $pointer])]);
    }

    /**
     * Any other error with a single message.
     */
    public static function create(int $status, string $title, string $detail): self
    {
        return new self($status, [self::error($status, $title, $detail)]);
    }

    /**
     * @param array{pointer?: string, parameter?: string} $source
     *
     * @return array<string, mixed>
     */
    public static function error(int $status, string $title, string $detail, array $source = []): array
    {
        $error = ['status' => (string) $status, 'title' => $title, 'detail' => $detail];
        if ($source) {
            $error['source'] = $source['pointer'] ?? null
                ? ['pointer' => $source['pointer']]
                : ['parameter' => $source['parameter']];
        }

        return $error;
    }
}
