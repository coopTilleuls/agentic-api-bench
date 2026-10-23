<?php

namespace App\EventListener;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Customer;
use App\Entity\Purchase;
use App\Http\JsonApiError;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Checks what the specification asks for before API Platform reads the body:
 * the Customer-Id header of purchases, the format of path ids, the query
 * parameters and the data.id of a PATCH. Runs after routing and before
 * deserialization, so its answers come first.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class RequestGuardListener
{
    private const MAX_ID = 2147483647;

    /** @var array<class-string, array{include: list<string>, sort: list<string>, filters: bool}> */
    private const RULES = [
        Book::class => ['include' => ['author'], 'sort' => ['priceCents', 'title', 'publishedAt'], 'filters' => true],
        Author::class => ['include' => ['books'], 'sort' => [], 'filters' => false],
        Purchase::class => ['include' => [], 'sort' => [], 'filters' => false],
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $resourceClass = $request->attributes->get('_api_resource_class');
        if (!\is_string($resourceClass) || !isset(self::RULES[$resourceClass])) {
            return;
        }

        if (Purchase::class === $resourceClass) {
            $customerId = $this->customerId($request);
            if (null === $customerId) {
                $event->setResponse(JsonApiError::response(401, 'Unauthorized', 'A valid Customer-Id header is required.'));

                return;
            }
            $request->attributes->set('_customer_id', $customerId);
        }

        $isItem = $request->attributes->has('id');
        if ($isItem && !self::isValidId($request->attributes->get('id'))) {
            $event->setResponse(JsonApiError::response(404, 'Not Found', 'The resource does not exist.'));

            return;
        }

        if ($isItem && Book::class === $resourceClass && 'PATCH' === $request->getMethod() && $this->isJsonApi($request)) {
            $body = json_decode($request->getContent(), true);
            $bodyId = \is_array($body) ? ($body['data']['id'] ?? null) : null;
            if (null !== $bodyId && (string) $bodyId !== (string) $request->attributes->get('id')) {
                $event->setResponse(JsonApiError::response(409, 'Conflict', 'The data id does not match the id of the URL.', '/data/id'));

                return;
            }
        }

        $error = $this->validateQuery($request, $resourceClass, $isItem);
        if (null !== $error) {
            $event->setResponse($error);
        }
    }

    private function customerId(Request $request): ?int
    {
        $header = $request->headers->get('Customer-Id');
        if (null === $header || !self::isValidId($header)) {
            return null;
        }

        $id = (int) $header;

        return null === $this->entityManager->find(Customer::class, $id) ? null : $id;
    }

    private static function isValidId(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/^[1-9]\d*$/', $value) && (int) $value <= self::MAX_ID;
    }

    private function isJsonApi(Request $request): bool
    {
        return str_starts_with(strtolower((string) $request->headers->get('Content-Type')), JsonApiError::CONTENT_TYPE);
    }

    private function validateQuery(Request $request, string $resourceClass, bool $isItem): ?JsonResponse
    {
        $rules = self::RULES[$resourceClass];
        // Raw values: InputBag::get() refuses arrays, and page[...] / filter[...] are arrays.
        $query = $request->query->all();

        if (!$isItem) {
            if (isset($query['page']) && !\is_array($query['page'])) {
                return $this->badParameter('page');
            }
            $page = $query['page'] ?? [];
            if (isset($page['number']) && !self::isPositiveInt($page['number'])) {
                return $this->badParameter('page[number]');
            }
            if (isset($page['size']) && (!self::isPositiveInt($page['size']) || (int) $page['size'] > 50)) {
                return $this->badParameter('page[size]');
            }
        }

        if (isset($query['include']) && !self::isListOf($query['include'], $rules['include'], false)) {
            return $this->badParameter('include');
        }

        if (!$isItem && isset($query['sort']) && !self::isListOf($query['sort'], $rules['sort'], true)) {
            return $this->badParameter('sort');
        }

        if (!$isItem && $rules['filters']) {
            $invalid = self::invalidFilter(\is_array($query['filter'] ?? null) ? $query['filter'] : []);
            if (null !== $invalid) {
                return $this->badParameter($invalid);
            }
        }

        return null;
    }

    private static function isPositiveInt(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/^[1-9]\d*$/', $value);
    }

    /**
     * @param list<string> $allowed
     */
    private static function isListOf(mixed $value, array $allowed, bool $signed): bool
    {
        if (!\is_string($value)) {
            return false;
        }
        foreach (explode(',', $value) as $part) {
            if (!\in_array($signed ? ltrim($part, '-') : $part, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return string|null the first invalid filter parameter, named as the client wrote it
     */
    private static function invalidFilter(array $filter): ?string
    {
        foreach (['title', 'author'] as $key) {
            if (isset($filter[$key]) && !\is_string($filter[$key])) {
                return "filter[$key]";
            }
        }
        foreach (['minPrice', 'maxPrice'] as $key) {
            if (isset($filter[$key]) && (!\is_string($filter[$key]) || 1 !== preg_match('/^-?\d+$/', $filter[$key]))) {
                return "filter[$key]";
            }
        }
        foreach (['publishedAfter', 'publishedBefore'] as $key) {
            if (isset($filter[$key]) && !self::isDay($filter[$key])) {
                return "filter[$key]";
            }
        }
        if (isset($filter['inStock']) && !\in_array($filter['inStock'], ['true', 'false'], true)) {
            return 'filter[inStock]';
        }

        return null;
    }

    private static function isDay(mixed $value): bool
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function badParameter(string $parameter): JsonResponse
    {
        return JsonApiError::response(400, 'Bad Request', "The query parameter $parameter is invalid.", parameter: $parameter);
    }
}
