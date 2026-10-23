<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * An error answered as a JSON:API error document.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $errors
     * @param array<string, string>      $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $errors,
        public readonly array $headers = [],
    ) {
        parent::__construct($errors[0]['detail'] ?? 'Error');
    }

    /**
     * @param array<string, string> $headers
     */
    public static function error(
        int $status,
        string $detail,
        ?string $pointer = null,
        ?string $parameter = null,
        array $headers = [],
    ): self {
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

        return new self($status, [$error], $headers);
    }
}
