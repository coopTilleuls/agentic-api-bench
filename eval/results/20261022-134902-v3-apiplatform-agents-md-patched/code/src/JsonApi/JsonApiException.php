<?php

namespace App\JsonApi;

/**
 * An error answered as a JSON:API error document.
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
        parent::__construct((string) ($errors[0]['detail'] ?? $errors[0]['title'] ?? 'Error'));
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

    public static function create(int $status, string $title, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        $error = ['status' => (string) $status, 'title' => $title, 'detail' => $detail];
        if (null !== $pointer) {
            $error['source'] = ['pointer' => $pointer];
        } elseif (null !== $parameter) {
            $error['source'] = ['parameter' => $parameter];
        }

        return new self($status, [$error]);
    }
}
