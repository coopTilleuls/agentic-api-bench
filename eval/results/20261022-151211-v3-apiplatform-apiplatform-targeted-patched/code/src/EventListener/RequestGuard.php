<?php

namespace App\EventListener;

use App\Api\ApiProblem;
use App\ApiResource\AuthorResource;
use App\ApiResource\BookResource;
use App\ApiResource\PurchaseResource;
use App\ApiResource\SalesReport;
use App\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Checks what must be checked before API Platform reads or deserializes the request:
 * the Customer-Id header, the body content type and the id of the path against the body.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
final class RequestGuard
{
    private const RESOURCES = [BookResource::class, AuthorResource::class, PurchaseResource::class, SalesReport::class];
    private const ID_PATTERN = '/^[1-9]\d{0,17}$/';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $resource = $request->attributes->get('_api_resource_class');
        if (!\in_array($resource, self::RESOURCES, true)) {
            return;
        }

        if (PurchaseResource::class === $resource) {
            $request->attributes->set('_customer_id', $this->customerId($request));
        }

        $id = $request->attributes->get('id');
        if (null !== $id && !preg_match(self::ID_PATTERN, (string) $id)) {
            throw ApiProblem::single(404, 'Resource not found.');
        }

        if (\in_array($request->getMethod(), ['POST', 'PATCH'], true)) {
            $this->checkContentType($request);
        }

        if ('PATCH' === $request->getMethod() && null !== $id) {
            $this->checkBodyId($request, (string) $id);
        }
    }

    private function customerId(Request $request): int
    {
        $header = $request->headers->get('Customer-Id');
        if (null === $header || !preg_match(self::ID_PATTERN, $header)) {
            throw ApiProblem::single(401, 'The Customer-Id header is missing or malformed.');
        }

        $id = (int) $header;
        if (null === $this->em->find(Customer::class, $id)) {
            throw ApiProblem::single(401, 'Unknown customer.');
        }

        return $id;
    }

    private function checkContentType(Request $request): void
    {
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if ('application/vnd.api+json' !== $type) {
            throw new UnsupportedMediaTypeHttpException('Content-Type must be application/vnd.api+json.');
        }
    }

    private function checkBodyId(Request $request, string $id): void
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !\is_array($body['data'] ?? null)) {
            throw ApiProblem::single(400, 'The request body must be a JSON:API document with a data member.');
        }

        $bodyId = $body['data']['id'] ?? null;
        if (null !== $bodyId && (string) $bodyId !== $id) {
            throw ApiProblem::single(409, sprintf('The data id "%s" does not match the id of the path "%s".', $bodyId, $id), pointer: '/data/id');
        }
    }
}
