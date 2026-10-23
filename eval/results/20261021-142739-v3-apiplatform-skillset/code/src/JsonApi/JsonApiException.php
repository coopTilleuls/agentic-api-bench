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
        public readonly int $status,
        public readonly array $errors,
    ) {
        parent::__construct($errors[0]['detail'] ?? 'Error');
    }

    public static function single(int $status, string $title, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        return new self($status, [self::error($status, $title, $detail, $pointer, $parameter)]);
    }

    public static function badRequest(string $detail, ?string $parameter = null): self
    {
        return self::single(400, 'Bad Request', $detail, null, $parameter);
    }

    /**
     * @param list<array{pointer: string, detail: string}> $violations
     */
    public static function unprocessable(array $violations): self
    {
        return new self(422, array_map(
            static fn (array $violation): array => self::error(422, 'Unprocessable Entity', $violation['detail'], $violation['pointer']),
            $violations,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(int $status, string $title, string $detail, ?string $pointer = null, ?string $parameter = null): array
    {
        $error = ['status' => (string) $status, 'title' => $title, 'detail' => $detail];

        if (null !== $pointer) {
            $error['source'] = ['pointer' => $pointer];
        } elseif (null !== $parameter) {
            $error['source'] = ['parameter' => $parameter];
        }

        return $error;
    }
}
