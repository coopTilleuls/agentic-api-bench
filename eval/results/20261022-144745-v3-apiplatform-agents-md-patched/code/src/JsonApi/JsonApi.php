<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class JsonApi
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    private const JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * @param array<string, mixed> $document
     * @param array<string, string> $headers
     */
    public static function response(array $document, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        return new Response(json_encode($document, self::JSON_FLAGS), $status, $headers + ['Content-Type' => self::MEDIA_TYPE]);
    }

    /**
     * A document with a page of resources; $included is given when the request asked for an include.
     *
     * @param array{number: int, size: int} $page
     * @param list<array<string, mixed>>    $data
     * @param list<array<string, mixed>>|null $included
     */
    public static function collection(Request $request, array $page, int $total, array $data, ?array $included = null): Response
    {
        $document = [
            'data' => $data,
            'links' => ListParameters::links($request, $page['number'], $page['size'], $total),
            'meta' => ['totalItems' => $total],
        ];

        if (null !== $included) {
            $document['included'] = $included;
        }

        return self::response($document);
    }

    /**
     * The "data" object of a request body: 415 for another content type, 400 for invalid JSON, 422 without a data object.
     *
     * @return array<string, mixed>
     */
    public static function requestData(Request $request): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if (self::MEDIA_TYPE !== $contentType) {
            throw JsonApiException::unsupportedMediaType('The Content-Type must be application/vnd.api+json.');
        }

        try {
            $document = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw JsonApiException::badRequest('The request body is not valid JSON.');
        }

        $data = \is_array($document) ? ($document['data'] ?? null) : null;
        if (!\is_array($data) || array_is_list($data)) {
            throw JsonApiException::validation(['/data' => 'The document must contain a data object.']);
        }

        return $data;
    }

    /**
     * Parses the id of a path, answering 404 when it is malformed.
     */
    public static function pathId(string $raw): int
    {
        if (!preg_match('/^[1-9]\d{0,8}$/', $raw)) {
            throw JsonApiException::notFound();
        }

        return (int) $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(int $status, string $title, string $detail, ?string $pointer = null, ?string $parameter = null): array
    {
        $error = ['status' => (string) $status, 'title' => $title, 'detail' => $detail];

        if (null !== $pointer) {
            $error['source'] = ['pointer' => $pointer];
        } elseif (null !== $parameter) {
            $error['source'] = ['parameter' => $parameter];
        }

        return $error;
    }
}
