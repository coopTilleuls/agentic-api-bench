<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Parsing and validation of request input. Every failure is a 400 (query) or 404 (path id).
 */
final class Input
{
    public const MAX_INT = 2147483647;
    private const MAX_PAGE = 1000000000000;

    public static function optionalString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw new ApiError(400, sprintf('Invalid "%s" parameter.', $key));
    }

    /**
     * @return array{page: int, perPage: int}
     */
    public static function pagination(Request $request, int $defaultPerPage = 10): array
    {
        $page = self::queryInt($request, 'page', 1, self::MAX_PAGE) ?? 1;
        $perPage = self::queryInt($request, 'itemsPerPage', 1, 50) ?? $defaultPerPage;

        return ['page' => $page, 'perPage' => $perPage];
    }

    /**
     * A boolean query value: "true" or "false", 400 otherwise; null when absent.
     */
    public static function optionalBoolean(Request $request, string $key): ?bool
    {
        return match (self::optionalString($request, $key)) {
            null => null,
            'true' => true,
            'false' => false,
            default => throw new ApiError(400, sprintf('"%s" must be true or false.', $key)),
        };
    }

    public static function queryInt(Request $request, string $key, int $min, int $max): ?int
    {
        $value = self::optionalString($request, $key);
        if (null === $value) {
            return null;
        }

        $int = filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if (false === $int) {
            throw new ApiError(400, sprintf('"%s" must be an integer between %d and %d.', $key, $min, $max));
        }

        return $int;
    }

    /**
     * A path id: anything malformed simply does not exist.
     */
    public static function pathId(string $id): int
    {
        $int = filter_var($id, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_INT]]);
        if (false === $int) {
            throw new ApiError(404, 'Not found');
        }

        return $int;
    }

    /**
     * The raw bounds of a range filter such as "priceCents[gte]=1000", keyed by bound name.
     *
     * @return array<string, string>
     */
    public static function range(Request $request, string $field): array
    {
        $value = $request->query->all()[$field] ?? [];
        if (!\is_array($value)) {
            throw new ApiError(400, sprintf('Invalid "%s" filter.', $field));
        }

        foreach ($value as $bound => $raw) {
            if (!\is_string($raw)) {
                throw new ApiError(400, sprintf('Invalid "%s[%s]" filter.', $field, $bound));
            }
        }

        return $value;
    }

    /**
     * An integer taken from a query value; 400 when malformed.
     */
    public static function integerValue(string $value, string $name): int
    {
        $int = filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => -self::MAX_INT, 'max_range' => self::MAX_INT]]);
        if (false === $int) {
            throw new ApiError(400, sprintf('"%s" must be an integer.', $name));
        }

        return $int;
    }

    /**
     * @return array<string, mixed>
     */
    public static function jsonObject(Request $request): array
    {
        $content = $request->getContent();
        if ('' === trim($content)) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError(400, 'Malformed JSON body.');
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new ApiError(422, 'The body must be a JSON object.');
        }

        return $decoded;
    }

    public static function timestamp(string $pgTimestamp): string
    {
        return (new \DateTimeImmutable($pgTimestamp))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    public static function date(string $value, string $name): \DateTimeImmutable
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ApiError(400, sprintf('"%s" must be a date formatted YYYY-MM-DD.', $name));
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
