<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/**
 * An error answered as a JSON:API error document. Each error may point at a body member
 * (source.pointer) or a query parameter or header (source.parameter).
 */
final class ApiProblem extends \RuntimeException
{
    /**
     * @param list<array{detail: string, source?: array{pointer?: string, parameter?: string}}> $errors
     */
    public function __construct(private readonly int $status, private readonly array $errors)
    {
        parent::__construct($errors[0]['detail'] ?? Response::$statusTexts[$status]);
    }

    public static function single(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        $error = ['detail' => $detail];
        if (null !== $pointer) {
            $error['source'] = ['pointer' => $pointer];
        } elseif (null !== $parameter) {
            $error['source'] = ['parameter' => $parameter];
        }

        return new self($status, [$error]);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * @return list<array{detail: string, source?: array{pointer?: string, parameter?: string}}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
