<?php

namespace App\Serializer;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Renders validation violations as JSON:API error objects with an absolute
 * source pointer (/data/attributes/priceCents, /data/attributes/lines/0/quantity).
 */
final class JsonApiViolationNormalizer implements NormalizerInterface
{
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $errors = [];
        foreach ($data as $violation) {
            $errors[] = [
                'status' => '422',
                'title' => 'Unprocessable Entity',
                'detail' => (string) $violation->getMessage(),
                'source' => ['pointer' => $this->pointer($violation)],
            ];
        }

        return ['errors' => $errors];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return 'jsonapi' === $format && $data instanceof ConstraintViolationListInterface;
    }

    public function getSupportedTypes(?string $format): array
    {
        return 'jsonapi' === $format ? [ConstraintViolationListInterface::class => true] : [];
    }

    private function pointer(ConstraintViolationInterface $violation): string
    {
        $path = (string) $violation->getPropertyPath();
        if ('' === $path) {
            return '/data';
        }

        // "lines[0].quantity" becomes "lines/0/quantity"
        $path = preg_replace('/\[(\d+)\]/', '/$1', $path);

        return '/data/attributes/'.str_replace('.', '/', $path);
    }
}
