<?php

namespace App\JsonApi;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rewrites the links of a JSON:API collection document: they keep every query parameter of the request
 * and point to page[number] and page[size].
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -100)]
final class CollectionLinksListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (200 !== $response->getStatusCode() || !str_starts_with((string) $response->headers->get('Content-Type'), 'application/vnd.api+json')) {
            return;
        }

        $document = json_decode((string) $response->getContent(), true);
        if (!\is_array($document) || !\array_key_exists('data', $document) || !array_is_list($document['data'] ?? null)
            || !isset($document['meta']['totalItems'])) {
            return;
        }

        $query = new QueryParameters($request);
        $page = $query->page();
        $total = (int) $document['meta']['totalItems'];
        $last = max(1, (int) ceil($total / $page['size']));

        $document['links'] = array_filter([
            'self' => $this->url($request, $page['number'], $page['size']),
            'first' => $this->url($request, 1, $page['size']),
            'last' => $this->url($request, $last, $page['size']),
            'prev' => $page['number'] > 1 ? $this->url($request, min($page['number'], $last + 1) - 1, $page['size']) : null,
            'next' => $page['number'] < $last ? $this->url($request, $page['number'] + 1, $page['size']) : null,
        ]);

        $response->setContent(json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private function url(\Symfony\Component\HttpFoundation\Request $request, int $number, int $size): string
    {
        $parameters = $request->query->all();
        $parameters['page'] = ['number' => $number, 'size' => $size];

        return $request->getSchemeAndHttpHost().$request->getPathInfo().'?'.http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986);
    }
}
