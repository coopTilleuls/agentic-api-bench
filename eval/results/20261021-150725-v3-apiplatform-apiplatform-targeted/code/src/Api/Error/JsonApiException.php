<?php

namespace App\Api\Error;

use Symfony\Component\HttpFoundation\Response;

/**
 * An error answered as a JSON:API error document, with the status and source of each error.
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
        parent::__construct($errors[0]['detail'] ?? Response::$statusTexts[$status] ?? 'Error', $status);
    }

    public static function single(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        return new self($status, [self::error($status, $detail, $pointer, $parameter)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): array
    {
        $error = [
            'status' => (string) $status,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'detail' => $detail,
        ];

        if (null !== $pointer) {
            $error['source']['pointer'] = $pointer;
        }

        if (null !== $parameter) {
            $error['source']['parameter'] = $parameter;
        }

        return $error;
    }
}
