<?php

namespace App\Http;

/**
 * An error answered as {"error": "<message>"} with the given HTTP status.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
