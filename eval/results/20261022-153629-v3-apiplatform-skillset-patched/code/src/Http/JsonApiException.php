<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * An error answered as a JSON:API error document.
 */
final class JsonApiException extends \RuntimeException
{
    /**
     * @param list<array{status: string, title: string, detail: string, source?: array<string, string>}> $errors
     */
    private function __construct(
        private readonly int $httpStatus,
        private readonly array $errors,
    ) {
        parent::__construct($errors[0]['detail']);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return list<array{status: string, title: string, detail: string, source?: array<string, string>}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function parameter(int $status, string $parameter, string $detail): self
    {
        return self::create($status, $detail, ['parameter' => $parameter]);
    }

    public static function pointer(int $status, string $pointer, string $detail): self
    {
        return self::create($status, $detail, ['pointer' => $pointer]);
    }

    public static function create(int $status, string $detail, ?array $source = null): self
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
     * One 422 error per invalid field, each pointing at it.
     *
     * @param list<array{0: string, 1: string}> $violations pairs of JSON pointer and detail
     */
    public static function validation(array $violations): self
    {
        $errors = [];
        foreach ($violations as [$pointer, $detail]) {
            $errors[] = ['status' => '422', 'title' => Response::$statusTexts[422], 'detail' => $detail, 'source' => ['pointer' => $pointer]];
        }

        return new self(422, $errors);
    }
}
