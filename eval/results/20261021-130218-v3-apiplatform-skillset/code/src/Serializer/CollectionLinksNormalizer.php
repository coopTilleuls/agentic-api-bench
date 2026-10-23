<?php

namespace App\Serializer;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Rewrites the JSON:API pagination links of a collection to use page[number] and page[size],
 * keeping every other query parameter (filters, sort, include) of the request.
 */
#[AsDecorator(decorates: 'api_platform.jsonapi.normalizer.collection')]
final class CollectionLinksNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly NormalizerInterface $inner,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): \ArrayObject|array|string|int|float|bool|null
    {
        $normalized = $this->inner->normalize($data, $format, $context);
        $request = $this->requestStack->getCurrentRequest();
        if (!$data instanceof PaginatorInterface || !is_array($normalized) || null === $request) {
            return $normalized;
        }

        $size = (int) $data->getItemsPerPage();
        $current = (int) $data->getCurrentPage();
        $last = (int) $data->getLastPage();
        $query = $request->query->all();

        $link = static fn (int $page): string => $request->getPathInfo().'?'.http_build_query(
            array_replace($query, ['page' => ['number' => $page, 'size' => $size]]),
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );

        $links = [
            'self' => $link($current),
            'first' => $link(1),
            'last' => $link($last),
        ];
        if ($current > 1) {
            $links['prev'] = $link(min($current - 1, $last));
        }
        if ($current < $last) {
            $links['next'] = $link($current + 1);
        }
        $normalized['links'] = $links;

        return $normalized;
    }

    public function setNormalizer(NormalizerInterface $normalizer): void
    {
        if ($this->inner instanceof NormalizerAwareInterface) {
            $this->inner->setNormalizer($normalizer);
        }
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
