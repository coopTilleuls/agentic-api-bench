<?php

namespace App\Serializer;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsDecorator('api_platform.jsonapi.normalizer.error')]
final readonly class ErrorSourceNormalizer implements NormalizerInterface
{
    public function __construct(#[AutowireDecorated] private NormalizerInterface $inner)
    {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $document = $this->inner->normalize($data, $format, $context);

        $cause = $data instanceof \Throwable ? $data->getPrevious() : null;
        if ($cause instanceof ValidationException) {
            $document['errors'][0]['source'] = ['parameter' => $cause->getConstraintViolationList()->get(0)->getPropertyPath()];
        }

        foreach ($document['errors'] as &$error) {
            unset($error['type'], $error['links']['type']);
            if ([] === ($error['links'] ?? null)) {
                unset($error['links']);
            }
        }

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
