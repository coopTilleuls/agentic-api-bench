<?php

namespace App\Http;

/**
 * An error rendered as a JSON:API error object.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $detail,
        private readonly ?string $pointer = null,
        private readonly ?string $parameter = null,
    ) {
        parent::__construct($detail);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getPointer(): ?string
    {
        return $this->pointer;
    }

    public function getParameter(): ?string
    {
        return $this->parameter;
    }
}
