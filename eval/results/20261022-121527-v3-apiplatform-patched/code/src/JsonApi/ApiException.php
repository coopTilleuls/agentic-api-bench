<?php

namespace App\JsonApi;

/**
 * An error answered as a JSON:API error document, possibly holding several error objects.
 */
final class ApiException extends \RuntimeException
{
    /** @param list<array<string, mixed>> $errors */
    public function __construct(private readonly int $status, private readonly array $errors)
    {
        parent::__construct((string) ($errors[0]['detail'] ?? 'Error'));
    }

    public static function of(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        return new self($status, [JsonApi::error($status, $detail, $pointer, $parameter)]);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return list<array<string, mixed>> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
