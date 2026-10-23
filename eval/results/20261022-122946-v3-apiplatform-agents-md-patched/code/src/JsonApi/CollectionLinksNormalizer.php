<?php

namespace App\JsonApi;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Gives every collection document the first, last, prev and next links of the JSON:API paging,
 * each keeping the filter, sort, include and page[size] parameters of the request.
 */
#[AsDecorator(decorates: 'api_platform.jsonapi.normalizer.collection')]
final class CollectionLinksNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function setNormalizer(NormalizerInterface $normalizer): void
    {
        if ($this->inner instanceof NormalizerAwareInterface) {
            $this->inner->setNormalizer($normalizer);
        }
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): \ArrayObject|array|string|int|float|bool|null
    {
        $document = $this->inner->normalize($data, $format, $context);
        $request = $this->requestStack->getCurrentRequest();
        if (!$data instanceof PaginatorInterface || null === $request || !\is_array($document)) {
            return $document;
        }

        $number = (int) $data->getCurrentPage();
        $lastPage = (int) $data->getLastPage();
        $query = [];
        parse_str((string) $request->getQueryString(), $query);

        $link = static function (int $page) use ($request, $query): string {
            $query['page'] = array_merge((array) ($query['page'] ?? []), ['number' => (string) $page]);
            $queryString = str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($query, '', '&', \PHP_QUERY_RFC3986));

            return $request->getSchemeAndHttpHost().$request->getPathInfo().'?'.$queryString;
        };

        $links = [
            'self' => $link($number),
            'first' => $link(1),
            'last' => $link($lastPage),
        ];
        if ($number > 1) {
            $links['prev'] = $link(min($number - 1, $lastPage));
        }
        if ($number < $lastPage) {
            $links['next'] = $link($number + 1);
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
}
