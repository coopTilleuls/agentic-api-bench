<?php

namespace App\JsonApi;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Writes the first, last, prev and next links of a JSON:API collection.
 *
 * API Platform builds them from the request's page parameter, which cannot
 * hold the nested page[number] and page[size] of JSON:API. Each link keeps
 * every other query parameter of the request.
 */
final class PaginationLinksNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $normalized = $this->inner->normalize($data, $format, $context);
        $request = $this->requestStack->getMainRequest();

        if (!$data instanceof PaginatorInterface || !\is_array($normalized) || null === $request) {
            return $normalized;
        }

        $normalized['links'] = $this->links($request, $data);

        return $normalized;
    }

    /**
     * The serializer injects itself into the normalizer it decorates.
     */
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

    /**
     * @return array<string, string>
     */
    private function links(Request $request, PaginatorInterface $paginator): array
    {
        $current = (int) $paginator->getCurrentPage();
        $last = (int) $paginator->getLastPage();
        $size = (int) $paginator->getItemsPerPage();

        $url = static function (int $page) use ($request, $size): string {
            $query = $request->query->all();
            $query['page'] = array_replace($query['page'] ?? [], ['number' => $page, 'size' => $size]);

            return $request->getPathInfo().'?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        };

        $links = [
            'self' => $url($current),
            'first' => $url(1),
            'last' => $url($last),
        ];
        if ($current > 1) {
            $links['prev'] = $url(min($current - 1, $last));
        }
        if ($current < $last) {
            $links['next'] = $url($current + 1);
        }

        return $links;
    }
}
