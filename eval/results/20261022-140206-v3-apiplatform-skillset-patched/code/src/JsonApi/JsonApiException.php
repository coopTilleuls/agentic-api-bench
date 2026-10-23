<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Response;

/**
 * A request that cannot be served, rendered as a JSON:API error document.
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
        parent::__construct($errors[0]['detail'] ?? Response::$statusTexts[$status] ?? 'Error');
    }

    public static function create(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        return new self($status, [self::errorObject($status, $detail, $pointer, $parameter)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function errorObject(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): array
    {
        $error = [
            'status' => (string) $status,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'detail' => $detail,
        ];

        if (null !== $pointer) {
            $error['source'] = ['pointer' => $pointer];
        } elseif (null !== $parameter) {
            $error['source'] = ['parameter' => $parameter];
        }

        return $error;
    }
}
