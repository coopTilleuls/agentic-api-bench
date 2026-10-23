<?php

namespace App\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsDecorator('api_platform.jsonapi.normalizer.collection')]
final readonly class PageLinksNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    public function __construct(#[AutowireDecorated] private NormalizerInterface $inner)
    {
    }

    public function setNormalizer(NormalizerInterface $normalizer): void
    {
        if ($this->inner instanceof NormalizerAwareInterface) {
            $this->inner->setNormalizer($normalizer);
        }
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $document = $this->inner->normalize($data, $format, $context);

        $links = $document['links'] ?? [];
        if (!\is_string($links['self'] ?? null)) {
            return $document;
        }
        foreach ($links as $rel => $link) {
            $links[$rel] = $this->toBracketedPage($link);
        }
        if (isset($links['self']) && !isset($links['first'])) {
            $links['first'] = $links['last'] = $this->toBracketedPage($links['self'], 1);
        }
        $document['links'] = $links;

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

    private function toBracketedPage(string $link, ?int $page = null): string
    {
        [$path, $query] = explode('?', $link, 2) + [1 => ''];
        parse_str($query, $parameters);
        if (null !== $page) {
            $parameters['page']['number'] = $page;
        }
        if (isset($parameters['number'])) {
            $parameters['page']['number'] = $parameters['number'];
            unset($parameters['number']);
        }

        $query = http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986);

        return '' === $query ? $path : $path.'?'.$query;
    }
}
