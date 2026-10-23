<?php

namespace App\JsonApi;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Replaces the pagination links and meta of collection documents with the page[number]
 * and page[size] links of the bookshop, keeping every other query parameter.
 */
#[AsDecorator(decorates: 'api_platform.jsonapi.normalizer.collection')]
final class PaginationDocumentNormalizer implements NormalizerInterface, NormalizerAwareInterface
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

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $document = $this->inner->normalize($object, $format, $context);

        $request = $this->requestStack->getCurrentRequest();
        if (!$object instanceof Paginator || !\is_array($document) || null === $request) {
            return $document;
        }

        $page = (int) $object->getCurrentPage();
        $size = (int) $object->getItemsPerPage();
        $lastPage = (int) $object->getLastPage();
        $query = $request->query->all();
        $link = static fn (int $number): string => $request->getPathInfo().'?'.str_replace(['%5B', '%5D'], ['[', ']'], http_build_query(array_replace($query, [
            'page' => ['number' => $number, 'size' => $size],
        ])));

        $links = [
            'self' => $link($page),
            'first' => $link(1),
            'last' => $link($lastPage),
        ];
        if ($page > 1) {
            $links['prev'] = $link($page - 1);
        }
        if ($page < $lastPage) {
            $links['next'] = $link($page + 1);
        }

        $document['links'] = $links;
        $document['meta'] = ['totalItems' => (int) $object->getTotalItems()];

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
