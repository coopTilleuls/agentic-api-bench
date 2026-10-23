<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Renders resources and collections as JSON:API documents.
 */
final class JsonApiNormalizer implements NormalizerInterface
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return 'json_api' === $format && ($data instanceof JsonApiResourceInterface || $data instanceof JsonApiCollection);
    }

    public function getSupportedTypes(?string $format): array
    {
        return 'json_api' === $format ? [JsonApiResourceInterface::class => true, JsonApiCollection::class => true] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $request = $this->requestStack->getCurrentRequest() ?? Request::create('/');
        $includes = $this->includes($request);

        if ($object instanceof JsonApiCollection) {
            $primary = $object->items;
            $document = [
                'data' => array_map($this->resource(...), $primary),
                'links' => $this->pageLinks($request, $object),
                'meta' => ['totalItems' => $object->total],
            ];
        } else {
            $primary = [$object];
            $document = ['data' => $this->resource($object)];
        }

        $included = $this->included($primary, $includes);
        if ([] !== $included) {
            $document['included'] = $included;
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(JsonApiResourceInterface $resource): array
    {
        $type = $resource->getJsonApiType();
        $id = $resource->getJsonApiId();
        $object = [
            'type' => $type,
            'id' => $id,
            'attributes' => (object) $resource->getJsonApiAttributes(),
        ];

        $relationships = [];
        foreach ($resource->getJsonApiRelationships() as $name => $related) {
            $relationships[$name] = ['data' => $this->linkage($related)];
        }
        if ([] !== $relationships) {
            $object['relationships'] = $relationships;
        }

        $object['links'] = ['self' => sprintf('/%s/%s', $type, $id)];

        return $object;
    }

    /**
     * @param JsonApiResourceInterface|list<JsonApiResourceInterface>|null $related
     *
     * @return array<string, string>|list<array<string, string>>|null
     */
    private function linkage(JsonApiResourceInterface|array|null $related): ?array
    {
        if (null === $related) {
            return null;
        }
        if (\is_array($related)) {
            return array_map(fn (JsonApiResourceInterface $item): array => ['type' => $item->getJsonApiType(), 'id' => $item->getJsonApiId()], $related);
        }

        return ['type' => $related->getJsonApiType(), 'id' => $related->getJsonApiId()];
    }

    /**
     * @param list<JsonApiResourceInterface> $primary
     * @param list<string>                  $includes
     *
     * @return list<array<string, mixed>>
     */
    private function included(array $primary, array $includes): array
    {
        if ([] === $includes) {
            return [];
        }

        $keys = [];
        foreach ($primary as $resource) {
            $keys[$resource->getJsonApiType().':'.$resource->getJsonApiId()] = true;
        }

        $included = [];
        foreach ($primary as $resource) {
            foreach ($includes as $name) {
                $relationships = $resource->getJsonApiRelationships();
                if (!\array_key_exists($name, $relationships)) {
                    continue;
                }
                $related = \is_array($relationships[$name]) ? $relationships[$name] : [$relationships[$name]];
                foreach ($related as $item) {
                    if (null === $item) {
                        continue;
                    }
                    $key = $item->getJsonApiType().':'.$item->getJsonApiId();
                    if (isset($keys[$key])) {
                        continue;
                    }
                    $keys[$key] = true;
                    $included[] = $this->resource($item);
                }
            }
        }

        return $included;
    }

    /**
     * @return list<string>
     */
    private function includes(Request $request): array
    {
        $include = $request->query->all()['include'] ?? null;
        if (!\is_string($include) || '' === $include) {
            return [];
        }

        return array_values(array_filter(explode(',', $include), static fn (string $name): bool => '' !== $name));
    }

    /**
     * @return array<string, string|null>
     */
    private function pageLinks(Request $request, JsonApiCollection $collection): array
    {
        $query = $request->query->all();
        $last = max(1, (int) ceil($collection->total / $collection->size));
        $url = function (int $number) use ($request, $query, $collection): string {
            $params = array_replace_recursive($query, ['page' => ['number' => $number, 'size' => $collection->size]]);

            return $request->getPathInfo().'?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
        };

        return [
            'first' => $url(1),
            'last' => $url($last),
            'prev' => $collection->number > 1 ? $url(min($collection->number - 1, $last)) : null,
            'next' => $collection->number < $last ? $url($collection->number + 1) : null,
        ];
    }
}
