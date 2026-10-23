<?php

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * An error answered as a JSON:API error document, with the exact status and source of each error.
 */
final class JsonApiException extends \RuntimeException
{
    /**
     * @param list<array{detail: string, source?: array{pointer?: string, parameter?: string}}> $errors
     */
    public function __construct(
        private readonly int $status,
        private readonly array $errors,
    ) {
        parent::__construct($errors[0]['detail'] ?? '');
    }

    public static function one(int $status, string $detail, ?array $source = null): self
    {
        return new self($status, [['detail' => $detail] + ($source ? ['source' => $source] : [])]);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * @return list<array{status: string, title: string, detail: string, source?: array{pointer?: string, parameter?: string}}>
     */
    public function getErrors(): array
    {
        $status = (string) $this->status;
        $title = Response::$statusTexts[$this->status] ?? 'Error';

        return array_map(
            static fn (array $error): array => ['status' => $status, 'title' => $title] + $error,
            $this->errors,
        );
    }
}
