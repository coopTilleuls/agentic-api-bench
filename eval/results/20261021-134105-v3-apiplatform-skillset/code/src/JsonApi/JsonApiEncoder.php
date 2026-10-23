<?php

namespace App\JsonApi;

use Symfony\Component\Serializer\Encoder\EncoderInterface;

final class JsonApiEncoder implements EncoderInterface
{
    public function supportsEncoding(string $format, array $context = []): bool
    {
        return 'json_api' === $format;
    }

    public function encode(mixed $data, string $format, array $context = []): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
    }
}
