<?php

namespace App\EventListener;

use App\Http\JsonApiError;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rewrites the pagination links of a JSON:API collection so that they carry
 * page[number] and page[size] next to every filter, sort and include of the request.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class JsonApiPaginationLinksListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if (!str_starts_with((string) $response->headers->get('Content-Type'), JsonApiError::CONTENT_TYPE)) {
            return;
        }

        $body = json_decode((string) $response->getContent(), true);
        if (!\is_array($body) || !\is_array($body['data'] ?? null) || !isset($body['meta']['totalItems'], $body['meta']['currentPage'], $body['meta']['itemsPerPage'])) {
            return;
        }

        $request = $event->getRequest();
        $query = $request->query->all();
        unset($query['page']);

        $size = max(1, (int) $body['meta']['itemsPerPage']);
        $page = max(1, (int) $body['meta']['currentPage']);
        $last = max(1, (int) ceil((int) $body['meta']['totalItems'] / $size));

        $link = function (int $number) use ($request, $query, $size): string {
            $parameters = array_merge($query, ['page' => ['number' => $number, 'size' => $size]]);
            $queryString = str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986));

            return $request->getPathInfo().'?'.$queryString;
        };

        $links = ['self' => $link($page), 'first' => $link(1), 'last' => $link($last)];
        if ($page > 1) {
            $links['prev'] = $link($page - 1);
        }
        if ($page < $last) {
            $links['next'] = $link($page + 1);
        }

        $body['links'] = $links;
        $response->setContent((string) json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }
}
