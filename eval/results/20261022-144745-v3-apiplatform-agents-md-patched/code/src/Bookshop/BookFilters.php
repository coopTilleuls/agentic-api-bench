<?php

namespace App\Bookshop;

use App\JsonApi\ListParameters;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the filter[...] parameters of the book lists. Unknown filters are ignored.
 */
final class BookFilters
{
    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        $criteria = [];

        if (null !== $value = ListParameters::filter($request, 'title')) {
            $criteria['title'] = $value;
        }
        if (null !== $value = ListParameters::filter($request, 'author')) {
            $criteria['author'] = $value;
        }
        if (null !== $value = ListParameters::filter($request, 'minPrice')) {
            $criteria['minPrice'] = ListParameters::integerFilter('minPrice', $value);
        }
        if (null !== $value = ListParameters::filter($request, 'maxPrice')) {
            $criteria['maxPrice'] = ListParameters::integerFilter('maxPrice', $value);
        }
        if (null !== $value = ListParameters::filter($request, 'publishedAfter')) {
            $criteria['publishedAfter'] = ListParameters::dateFilter('publishedAfter', $value);
        }
        if (null !== $value = ListParameters::filter($request, 'publishedBefore')) {
            $criteria['publishedBefore'] = ListParameters::dateFilter('publishedBefore', $value);
        }
        if (null !== $value = ListParameters::filter($request, 'inStock')) {
            $criteria['inStock'] = ListParameters::booleanFilter('inStock', $value);
        }

        return $criteria;
    }
}
