<?php

namespace App\Controller;

use App\Bookshop\Purchases;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseCreate
{
    public function __construct(private readonly Purchases $purchases)
    {
    }

    public function __invoke(Request $request): Response
    {
        $customerId = $this->purchases->identify($request);

        if (!JsonApi::isJsonApiContentType($request->headers->get('Content-Type'))) {
            throw JsonApiException::single(415, 'Unsupported Media Type', 'The Content-Type must be application/vnd.api+json.');
        }

        $data = JsonApi::requestData($request->getContent(), 'purchases');
        $lines = Purchases::parseLines($data['attributes'] ?? null);

        $purchase = $this->purchases->buy($customerId, $lines);

        return JsonApi::response(['data' => $purchase], 201, [
            'Location' => $request->getSchemeAndHttpHost() . '/purchases/' . $purchase['id'],
        ]);
    }
}
