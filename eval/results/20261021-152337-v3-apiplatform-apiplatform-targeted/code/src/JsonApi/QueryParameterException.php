<?php

namespace App\JsonApi;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * An invalid query parameter: answers 400, with source.parameter naming it.
 */
final class QueryParameterException extends BadRequestHttpException
{
    public function __construct(private readonly string $parameter, string $detail)
    {
        parent::__construct($detail);
    }

    public function getParameter(): string
    {
        return $this->parameter;
    }
}
