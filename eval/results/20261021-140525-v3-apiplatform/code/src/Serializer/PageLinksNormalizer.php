<?php

namespace App\Serializer;

use App\Pagination\Page;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds the JSON:API links of a page of a collection (first, last, prev, next), keeping the
 * filters, sort and page size of the request. The API Platform normalizer does not keep
 * the nested page[size] parameter, so the links are built here.
 */
final class PageLinksNormalizer implements NormalizerInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.jsonapi.normalizer.collection')]
        private readonly NormalizerInterface $collectionNormalizer,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $document = $this->collectionNormalizer->normalize($data, $format, $context);

        $request = $this->requestStack->getCurrentRequest();
        $base = $request->getSchemeAndHttpHost() . $request->getPathInfo();
        $query = $request->query->all();
        $size = (int) $data->getItemsPerPage();
        $current = (int) $data->getCurrentPage();
        $last = (int) $data->getLastPage();

        $url = static function (int $number) use ($base, $query, $size): string {
            $query['page'] = ['number' => $number, 'size' => $size];

            return $base . '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        };

        $document['links'] = [
            'self' => $url($current),
            'first' => $url(1),
            'last' => $url($last),
            'prev' => $current > 1 ? $url(min($current - 1, $last)) : null,
            'next' => $current < $last ? $url($current + 1) : null,
        ];

        return $document;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return 'jsonapi' === $format && $data instanceof Page;
    }

    public function getSupportedTypes(?string $format): array
    {
        return 'jsonapi' === $format ? [Page::class => true] : [];
    }
}
