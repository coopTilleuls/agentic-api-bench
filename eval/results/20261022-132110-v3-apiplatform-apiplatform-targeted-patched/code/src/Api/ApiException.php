<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/**
 * An error answered as a JSON:API error document.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param list<array{detail: string, pointer: ?string, parameter: ?string}> $problems
     */
    private function __construct(
        private readonly int $status,
        private readonly array $problems,
    ) {
        parent::__construct($problems[0]['detail']);
    }

    public static function status(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        return new self($status, [['detail' => $detail, 'pointer' => $pointer, 'parameter' => $parameter]]);
    }

    /**
     * @param list<array{detail: string, pointer?: ?string, parameter?: ?string}> $problems
     */
    public static function problems(int $status, array $problems): self
    {
        return new self($status, array_map(static fn (array $problem): array => $problem + ['pointer' => null, 'parameter' => null], $problems));
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
        return array_map(function (array $problem): array {
            $error = [
                'status' => (string) $this->status,
                'title' => Response::$statusTexts[$this->status] ?? 'Error',
                'detail' => $problem['detail'],
            ];

            if (null !== $problem['pointer']) {
                $error['source'] = ['pointer' => $problem['pointer']];
            } elseif (null !== $problem['parameter']) {
                $error['source'] = ['parameter' => $problem['parameter']];
            }

            return $error;
        }, $this->problems);
    }
}
