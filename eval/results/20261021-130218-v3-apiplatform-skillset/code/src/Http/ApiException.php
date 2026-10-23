<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * An error answered as a JSON:API error document.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param list<array{status: string, title: string, detail: string, source?: array<string, string>}> $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly array $errors,
    ) {
        parent::__construct($errors[0]['detail'] ?? '');
    }

    /**
     * @param array<string, string> $source "parameter" or "pointer" naming the offending input
     */
    public static function single(int $status, string $detail, array $source = []): self
    {
        return new self($status, [self::error($status, $detail, $source)]);
    }

    /**
     * @param array<string, string> $source
     *
     * @return array{status: string, title: string, detail: string, source?: array<string, string>}
     */
    public static function error(int $status, string $detail, array $source = []): array
    {
        $error = [
            'status' => (string) $status,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'detail' => $detail,
        ];
        if ([] !== $source) {
            $error['source'] = $source;
        }

        return $error;
    }
}
