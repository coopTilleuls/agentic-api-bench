<?php

namespace App\Http;

/**
 * Carries a complete list of JSON:API error objects and the HTTP status they are answered with.
 */
class JsonApiException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(private readonly int $status, private readonly array $errors)
    {
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
     * @param list<array{pointer: string, detail: string}> $violations
     */
    public static function validation(array $violations): self
    {
        return new self(422, array_map(static fn (array $violation) => [
            'status' => '422',
            'title' => 'Invalid attribute',
            'detail' => $violation['detail'],
            'source' => ['pointer' => $violation['pointer']],
        ], $violations));
    }

    public static function single(int $status, string $title, string $detail): self
    {
        return new self($status, [['status' => (string) $status, 'title' => $title, 'detail' => $detail]]);
    }
}
