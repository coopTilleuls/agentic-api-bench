<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Response;

/**
 * An error answered as a JSON:API error document.
 */
final class JsonApiException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $errors
     */
    private function __construct(private readonly int $status, private readonly array $errors)
    {
        parent::__construct($errors[0]['detail'] ?? Response::$statusTexts[$status] ?? 'Error');
    }

    /**
     * @param array{parameter?: string, pointer?: string, header?: string}|null $source
     */
    public static function error(int $status, string $detail, ?array $source = null): self
    {
        $error = [
            'status' => (string) $status,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'detail' => $detail,
        ];
        if (null !== $source) {
            $error['source'] = $source;
        }

        return new self($status, [$error]);
    }

    /**
     * @param list<array{pointer: string, detail: string}> $violations
     */
    public static function validation(array $violations): self
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = [
                'status' => '422',
                'title' => Response::$statusTexts[422],
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
     * @return list<array<string, mixed>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
