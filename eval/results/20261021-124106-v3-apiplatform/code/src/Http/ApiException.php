<?php

namespace App\Http;

/**
 * A failure answered with a JSON:API error document.
 */
final class ApiException extends \RuntimeException
{
    /** @param list<array<string, mixed>> $errors */
    public function __construct(public readonly int $status, public readonly array $errors)
    {
        parent::__construct((string) ($errors[0]['detail'] ?? 'Error'));
    }

    public static function one(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        return new self($status, [JsonApi::error($status, $detail, $pointer, $parameter)]);
    }
}
