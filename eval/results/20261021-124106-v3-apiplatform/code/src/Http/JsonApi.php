<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds JSON:API documents, links and error objects.
 */
final class JsonApi
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    public const DEFAULT_SIZE = 10;
    public const MAX_SIZE = 50;

    /** @param array<string, mixed> $document */
    public static function respond(array $document, int $status = 200, array $headers = []): Response
    {
        $response = new Response(
            json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            $headers + ['Content-Type' => self::MEDIA_TYPE],
        );

        return $response;
    }

    public static function error(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): array
    {
        $error = [
            'status' => (string) $status,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'detail' => $detail,
        ];
        if (null !== $pointer) {
            $error['source'] = ['pointer' => $pointer];
        } elseif (null !== $parameter) {
            $error['source'] = ['parameter' => $parameter];
        }

        return $error;
    }

    /**
     * @param array<string, mixed>      $attributes
     * @param array<string, mixed>|null $relationships
     *
     * @return array<string, mixed>
     */
    public static function resource(string $type, string $id, array $attributes, ?array $relationships = null): array
    {
        $resource = ['type' => $type, 'id' => $id, 'attributes' => $attributes];
        if (null !== $relationships) {
            $resource['relationships'] = $relationships;
        }
        $resource['links'] = ['self' => "/$type/$id"];

        return $resource;
    }

    /**
     * @param list<array<string, mixed>> $data
     * @param list<array<string, mixed>> $included
     *
     * @return array<string, mixed>
     */
    public static function collection(Request $request, array $data, int $total, int $number, int $size, array $included = []): array
    {
        $last = max(1, (int) ceil($total / $size));
        $document = [
            'data' => $data,
            'links' => [
                'self' => self::pageUrl($request, $number, $size),
                'first' => self::pageUrl($request, 1, $size),
                'last' => self::pageUrl($request, $last, $size),
                'prev' => $number > 1 ? self::pageUrl($request, $number - 1, $size) : null,
                'next' => $number < $last ? self::pageUrl($request, $number + 1, $size) : null,
            ],
            'meta' => ['totalItems' => $total],
        ];
        if ([] !== $included) {
            $document['included'] = $included;
        }

        return $document;
    }

    /**
     * Keeps every query parameter of the request except the page number, which is set to the target page.
     */
    private static function pageUrl(Request $request, int $number, int $size): string
    {
        $parts = [];
        foreach ($request->query->all() as $key => $value) {
            if ('page' === $key) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (is_string($subValue)) {
                        $parts[] = rawurlencode((string) $key).'['.rawurlencode((string) $subKey).']='.rawurlencode($subValue);
                    }
                }
            } elseif (is_string($value)) {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode($value);
            }
        }
        $parts[] = "page[number]=$number";
        $parts[] = "page[size]=$size";

        return $request->getPathInfo().'?'.implode('&', $parts);
    }

    /**
     * Reads the body of a JSON:API request, checking its media type.
     *
     * @return array<string, mixed> the `data` member of the document
     */
    public static function requestData(Request $request): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        if (self::MEDIA_TYPE !== $contentType) {
            throw ApiException::one(415, 'Content-Type must be '.self::MEDIA_TYPE);
        }

        try {
            $document = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::one(400, 'The request body is not valid JSON.');
        }
        if (!is_array($document) || !isset($document['data']) || !is_array($document['data']) || array_is_list($document['data'])) {
            throw ApiException::one(400, 'The request body must be a JSON:API document with a data object.', '/data');
        }

        return $document['data'];
    }
}
