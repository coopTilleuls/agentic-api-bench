<?php

namespace App\Exception;

/**
 * An error answered as a JSON:API error document.
 *
 * Each entry of $errors has "title", "detail" and optionally "source".
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param list<array{title: string, detail: string, source?: array<string, string>}> $errors
     */
    public function __construct(
        private readonly int $status,
        private readonly array $errors,
    ) {
        parent::__construct($errors[0]['detail'] ?? $errors[0]['title'] ?? 'Error', $status);
    }

    public static function single(int $status, string $title, string $detail, ?array $source = null): self
    {
        $error = ['title' => $title, 'detail' => $detail];
        if (null !== $source) {
            $error['source'] = $source;
        }

        return new self($status, [$error]);
    }

    public static function badParameter(string $parameter, string $detail): self
    {
        return self::single(400, 'Bad Request', $detail, ['parameter' => $parameter]);
    }

    public static function invalidBody(string $pointer, string $detail): self
    {
        return self::single(422, 'Unprocessable Entity', $detail, ['pointer' => $pointer]);
    }

    /**
     * @param list<array{pointer: string, detail: string}> $violations
     */
    public static function validation(array $violations): self
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = [
                'title' => 'Unprocessable Entity',
                'detail' => $violation['detail'],
                'source' => ['pointer' => $violation['pointer']],
            ];
        }

        return new self(422, $errors);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return list<array{title: string, detail: string, source?: array<string, string>}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
