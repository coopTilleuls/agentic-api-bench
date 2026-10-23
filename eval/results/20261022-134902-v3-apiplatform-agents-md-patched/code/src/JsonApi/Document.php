<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds JSON:API documents and links.
 */
final class Document
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function response(array $body, int $status = 200, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($body, $status, $headers);
        $response->headers->set('Content-Type', self::MEDIA_TYPE);

        return $response;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $relationships
     * @return array<string, mixed>
     */
    public static function resource(Request $request, string $type, int|string $id, array $attributes, array $relationships = []): array
    {
        $resource = [
            'type' => $type,
            'id' => (string) $id,
            'attributes' => $attributes,
        ];
        if ([] !== $relationships) {
            $resource['relationships'] = $relationships;
        }
        $resource['links'] = ['self' => self::baseUrl($request).'/'.$type.'/'.$id];

        return $resource;
    }

    /**
     * Relationship object with resource linkage.
     *
     * @return array{data: array{type: string, id: string}}
     */
    public static function toOne(string $type, int|string $id): array
    {
        return ['data' => ['type' => $type, 'id' => (string) $id]];
    }

    /**
     * @param list<int|string> $ids
     * @return array{data: list<array{type: string, id: string}>}
     */
    public static function toMany(string $type, array $ids): array
    {
        return ['data' => array_map(static fn ($id) => ['type' => $type, 'id' => (string) $id], $ids)];
    }

    /**
     * @param list<array<string, mixed>> $included
     * @return array<string, mixed>
     */
    public static function single(array $resource, array $included = []): array
    {
        $document = ['data' => $resource];
        if ([] !== $included) {
            $document['included'] = self::uniqueResources($included);
        }

        return $document;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $included
     * @return array<string, mixed>
     */
    public static function collection(Request $request, array $items, int $total, int $page, int $size, array $included = []): array
    {
        $lastPage = max(1, (int) ceil($total / $size));
        $document = [
            'data' => $items,
            'links' => [
                'first' => self::pageUrl($request, 1, $size),
                'last' => self::pageUrl($request, $lastPage, $size),
                'prev' => $page > 1 ? self::pageUrl($request, $page - 1, $size) : null,
                'next' => $page < $lastPage ? self::pageUrl($request, $page + 1, $size) : null,
            ],
            'meta' => ['totalItems' => $total],
        ];
        if ([] !== $included) {
            $document['included'] = self::uniqueResources($included);
        }

        return $document;
    }

    public static function baseUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost().$request->getBaseUrl();
    }

    /**
     * URL of the current request path with every query parameter kept, except the page number.
     */
    public static function pageUrl(Request $request, int $page, int $size): string
    {
        $query = $request->query->all();
        $query['page'] = ['number' => (string) $page, 'size' => (string) $size];

        $parts = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $parts[] = rawurlencode((string) $key).'['.rawurlencode((string) $subKey).']='.rawurlencode((string) $subValue);
                }
            } else {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
            }
        }

        return self::baseUrl($request).$request->getPathInfo().'?'.implode('&', $parts);
    }

    /**
     * @param list<array<string, mixed>> $resources
     * @return list<array<string, mixed>>
     */
    private static function uniqueResources(array $resources): array
    {
        $unique = [];
        foreach ($resources as $resource) {
            $unique[$resource['type'].'/'.$resource['id']] = $resource;
        }

        return array_values($unique);
    }
}
