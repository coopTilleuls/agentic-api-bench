<?php

namespace App\Serializer;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Gives every JSON:API collection the links first, last, prev and next, each keeping the query of the
 * request (filters, sort, include, page size). API Platform leaves first and last out of one-page
 * collections, and prev and next are only set in the ranges it expects.
 */
#[AsDecorator(decorates: 'api_platform.jsonapi.normalizer.collection')]
final class CollectionLinksNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    public function __construct(private readonly NormalizerInterface $inner)
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
        $normalized = $this->inner->normalize($data, $format, $context);
        if (!$data instanceof PaginatorInterface || !\is_array($normalized)) {
            return $normalized;
        }

        $uri = (string) ($context['uri'] ?? '/');
        $path = (string) parse_url($uri, \PHP_URL_PATH);
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);

        $page = (int) $data->getCurrentPage();
        $last = (int) $data->getLastPage();
        $link = static function (int $number) use ($path, $query): string {
            $parameters = $query;
            $parameters['page'] = ['number' => $number] + (\is_array($query['page'] ?? null) ? array_diff_key($query['page'], ['number' => true]) : []);

            return $path.'?'.str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986));
        };

        $links = [
            'self' => $link($page),
            'first' => $link(1),
            'last' => $link($last),
        ];
        if ($page > 1) {
            $links['prev'] = $link($page - 1);
        }
        if ($page < $last) {
            $links['next'] = $link($page + 1);
        }

        $normalized['links'] = $links;

        return $normalized;
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
