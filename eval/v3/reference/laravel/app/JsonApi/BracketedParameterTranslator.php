<?php

namespace App\JsonApi;

use LaravelJsonApi\Core\Document\Error;
use LaravelJsonApi\Validation\Translator;

class BracketedParameterTranslator extends Translator
{
    public function invalidQueryParameter(string $param, ?string $detail = null, array $failed = []): Error
    {
        $bracketed = preg_replace('/^([^.]+)\.(.+)$/', '$1[$2]', $param);

        return parent::invalidQueryParameter($bracketed, $detail, $failed);
    }
}
