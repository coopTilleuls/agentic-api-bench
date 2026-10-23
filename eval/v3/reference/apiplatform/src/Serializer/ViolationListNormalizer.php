<?php

namespace App\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsDecorator('api_platform.jsonapi.normalizer.constraint_violation_list')]
final readonly class ViolationListNormalizer implements NormalizerInterface
{
    public function __construct(#[AutowireDecorated] private NormalizerInterface $inner)
    {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $errors = [];
        foreach ($data as $violation) {
            $path = preg_replace('/\[(\w+)\]/', '.$1', $violation->getPropertyPath());
            $errors[] = [
                'status' => (string) Response::HTTP_UNPROCESSABLE_ENTITY,
                'title' => Response::$statusTexts[Response::HTTP_UNPROCESSABLE_ENTITY],
                'detail' => $violation->getMessage(),
                'source' => ['pointer' => '/data/attributes'.('' === $path ? '' : '/'.str_replace('.', '/', $path))],
            ];
        }

        return ['errors' => $errors];
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
