<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adapts the generated OpenAPI document to the specification: OpenAPI 3.1, the
 * JSON:API page[number] and page[size] parameters, and no empty links objects.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -20)]
final class OpenApiDocumentListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if ('api_platform_openapi' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $response = $event->getResponse();
        $document = json_decode((string) $response->getContent(), true);
        if (!\is_array($document)) {
            return;
        }

        $document['openapi'] = '3.1.0';

        foreach ($document['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (!\is_array($operation)) {
                    continue;
                }
                foreach ($operation['parameters'] ?? [] as $index => $parameter) {
                    if ('query' === ($parameter['in'] ?? null) && \in_array($parameter['name'], ['number', 'size'], true)) {
                        $document['paths'][$path][$method]['parameters'][$index] = $this->pagination($parameter['name']);
                    }
                }
                foreach ($operation['responses'] ?? [] as $code => $content) {
                    if (\is_array($content) && isset($content['links']) && [] === $content['links']) {
                        unset($document['paths'][$path][$method]['responses'][$code]['links']);
                    }
                }
            }
        }

        $response->setContent((string) json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    private function pagination(string $name): array
    {
        return 'number' === $name
            ? ['name' => 'page[number]', 'in' => 'query', 'description' => 'The page number, from 1', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]]
            : ['name' => 'page[size]', 'in' => 'query', 'description' => 'The number of items per page, from 1 to 50', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]];
    }
}
