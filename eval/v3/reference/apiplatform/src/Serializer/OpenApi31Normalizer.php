<?php

namespace App\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsDecorator('api_platform.openapi.normalizer')]
final readonly class OpenApi31Normalizer implements NormalizerInterface
{
    public function __construct(#[AutowireDecorated] private NormalizerInterface $inner)
    {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $document = $this->inner->normalize($data, $format, $context);
        $document['openapi'] = '3.1.0';

        return $document;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }
}
