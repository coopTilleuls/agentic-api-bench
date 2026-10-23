<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Makes sure every JSON:API list carries first, last, prev and next links, also when there is a single page.
 */
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

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $data = $this->inner->normalize($object, $format, $context);

        if (!$object instanceof PaginatorInterface || !isset($data['links']['self'])) {
            return $data;
        }

        $self = $data['links']['self'];
        $lastPage = max(1, (int) $object->getLastPage());
        $currentPage = (int) $object->getCurrentPage();

        $links = [
            'self' => $self,
            'first' => $this->pageLink($self, 1),
            'last' => $this->pageLink($self, $lastPage),
        ];
        if ($currentPage > 1) {
            $links['prev'] = $this->pageLink($self, $currentPage - 1);
        }
        if ($currentPage < $lastPage) {
            $links['next'] = $this->pageLink($self, $currentPage + 1);
        }

        $data['links'] = $links;

        return $data;
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
     * Same URL as the self link, with the page number changed and every other parameter kept.
     */
    private function pageLink(string $self, int $page): string
    {
        $parts = parse_url($self);
        parse_str($parts['query'] ?? '', $query);

        $pageParameters = \is_array($query['page'] ?? null) ? $query['page'] : [];
        $query['page'] = ['number' => $page] + $pageParameters;

        $url = (isset($parts['scheme']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : '')
            .($parts['path'] ?? '/')
            .'?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);

        return $url;
    }
}
