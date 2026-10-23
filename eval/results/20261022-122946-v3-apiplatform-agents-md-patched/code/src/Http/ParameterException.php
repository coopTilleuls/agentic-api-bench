<?php

namespace App\Http;

/**
 * An invalid query parameter, answered with a 400 JSON:API error naming it.
 */
final class ParameterException extends JsonApiException
{
    public function __construct(string $parameter, string $detail)
    {
        parent::__construct(400, [[
            'status' => '400',
            'title' => 'Invalid query parameter',
            'detail' => $detail,
            'source' => ['parameter' => $parameter],
        ]]);
    }
}
