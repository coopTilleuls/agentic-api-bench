<?php

namespace App\Api\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Makes the pagination links of a collection right:
 * - API Platform appends "page[number]" to the query string of the request, which repeats it when the request already
 *   has one: each link is rebuilt keeping the last value of each parameter, which is the page API Platform appended;
 * - an empty collection has no first and last links: they are the first page and the last page, which is the first one;
 * - past the last page there is no next page, and the previous one is the last page.
 */
#[AsDecorator(decorates: 'api_platform.jsonapi.normalizer.collection')]
final class CollectionLinksNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly NormalizerInterface&NormalizerAwareInterface $inner,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $normalized = $this->inner->normalize($data, $format, $context);

        if (!\is_array($normalized) || !\is_array($normalized['links'] ?? null)) {
            return $normalized;
        }

        foreach ($normalized['links'] as $rel => $link) {
            if (\is_string($link)) {
                $normalized['links'][$rel] = $this->rebuild($link);
            }
        }

        $meta = $normalized['meta'] ?? [];

        if (isset($meta['totalItems'], $meta['itemsPerPage'], $normalized['links']['self']) && !isset($normalized['links']['last'])) {
            $lastPage = max(1, (int) ceil($meta['totalItems'] / max(1, $meta['itemsPerPage'])));
            $normalized['links']['first'] = $this->rebuild($normalized['links']['self'], 1);
            $normalized['links']['last'] = $this->rebuild($normalized['links']['self'], $lastPage);
        }

        $last = isset($normalized['links']['last']) ? $this->pageOf($normalized['links']['last']) : null;
        $current = $meta['currentPage'] ?? null;

        if (null !== $last && null !== $current && $current > $last) {
            unset($normalized['links']['next']);
            $normalized['links']['prev'] = $normalized['links']['last'];
        }

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

    public function setNormalizer(NormalizerInterface $normalizer): void
    {
        $this->inner->setNormalizer($normalizer);
    }

    private function rebuild(string $link, ?int $page = null): string
    {
        $parts = parse_url($link);

        if (false === $parts) {
            return $link;
        }

        parse_str($parts['query'] ?? '', $query);

        if (null !== $page) {
            $query['page']['number'] = $page;
        }

        $url = ($parts['path'] ?? '').'?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);

        return isset($parts['host']) ? ($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$url : $url;
    }

    private function pageOf(string $link): ?int
    {
        parse_str((string) parse_url($link, \PHP_URL_QUERY), $query);

        return isset($query['page']['number']) ? (int) $query['page']['number'] : null;
    }
}
