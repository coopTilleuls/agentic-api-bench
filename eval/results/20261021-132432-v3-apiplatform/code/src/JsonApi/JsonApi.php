<?php

namespace App\JsonApi;

use App\Http\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON:API response, body and pagination helpers.
 */
final class JsonApi
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /** @param array<string, string> $headers */
    public static function respond(mixed $document, int $status = 200, array $headers = []): JsonResponse
    {
        $headers['Content-Type'] = self::MEDIA_TYPE;

        $response = new JsonResponse($document, $status, $headers);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $response;
    }

    /**
     * Decodes the JSON:API request body, answering 415 for another media type.
     *
     * @return array<string, mixed>
     */
    public static function requestDocument(Request $request): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if (self::MEDIA_TYPE !== $contentType) {
            throw ApiException::error(415, 'The request body must be sent as '.self::MEDIA_TYPE.'.');
        }

        $document = json_decode($request->getContent(), true);
        if (!is_array($document) || array_is_list($document)) {
            throw ApiException::error(400, 'The request body is not a valid JSON object.');
        }

        return $document;
    }

    /**
     * Pagination links and the matching meta, for a list of $total items on page $number of $size.
     *
     * @return array{links: array<string, string|null>, meta: array<string, int>}
     */
    public static function page(Request $request, int $number, int $size, int $total): array
    {
        $last = max(1, (int) ceil($total / $size));
        $url = static fn (int $page): string => self::url($request, $page, $size);

        return [
            'links' => [
                'self' => $url($number),
                'first' => $url(1),
                'last' => $url($last),
                'prev' => $number > 1 ? $url($number - 1) : null,
                'next' => $number < $last ? $url($number + 1) : null,
            ],
            'meta' => ['totalItems' => $total],
        ];
    }

    /** The absolute URL of a path, on the host the request was made to. */
    public static function absolute(Request $request, string $path): string
    {
        return $request->getSchemeAndHttpHost().$path;
    }

    private static function url(Request $request, int $page, int $size): string
    {
        // Every parameter of the request is kept, the page parameters are replaced.
        $pairs = ['page[number]='.$page, 'page[size]='.$size];
        foreach ($request->query->all() as $key => $value) {
            if ('page' === $key) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (is_scalar($subValue)) {
                        $pairs[] = $key.'['.$subKey.']='.rawurlencode((string) $subValue);
                    }
                }
            } elseif (is_scalar($value)) {
                $pairs[] = $key.'='.rawurlencode((string) $value);
            }
        }

        return self::absolute($request, $request->getPathInfo()).'?'.implode('&', $pairs);
    }
}
