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
    public function __construct(public readonly int $status, public readonly array $errors)
    {
        parent::__construct($errors[0]['detail'] ?? 'Error');
    }

    public static function single(int $status, string $title, string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        return new self($status, [JsonApi::error($status, $title, $detail, $pointer, $parameter)]);
    }

    public static function badRequest(string $detail, ?string $parameter = null): self
    {
        return self::single(400, 'Bad Request', $detail, null, $parameter);
    }

    public static function unauthorized(string $detail): self
    {
        return self::single(401, 'Unauthorized', $detail);
    }

    public static function notFound(string $detail = 'The requested resource does not exist.'): self
    {
        return self::single(404, 'Not Found', $detail);
    }

    public static function conflict(string $detail, ?string $pointer = null): self
    {
        return self::single(409, 'Conflict', $detail, $pointer);
    }

    public static function unsupportedMediaType(string $detail): self
    {
        return self::single(415, 'Unsupported Media Type', $detail);
    }

    /**
     * @param array<string, string> $fieldErrors detail by source pointer
     */
    public static function validation(array $fieldErrors): self
    {
        $errors = [];
        foreach ($fieldErrors as $pointer => $detail) {
            $errors[] = JsonApi::error(422, 'Unprocessable Entity', $detail, $pointer);
        }

        return new self(422, $errors);
    }

    public static function paymentRequired(string $detail): self
    {
        return self::single(402, 'Payment Required', $detail);
    }

    public static function badGateway(string $detail): self
    {
        return self::single(502, 'Bad Gateway', $detail);
    }
}
