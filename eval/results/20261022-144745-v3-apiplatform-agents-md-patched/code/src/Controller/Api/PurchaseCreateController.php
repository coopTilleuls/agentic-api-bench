<?php

namespace App\Controller\Api;

use App\Bookshop\CustomerResolver;
use App\Bookshop\Presenter;
use App\Bookshop\Purchases;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseCreateController
{
    public function __construct(
        private readonly CustomerResolver $customers,
        private readonly Purchases $purchases,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $customerId = $this->customers->resolve($request);

        $data = JsonApi::requestData($request);
        if ('purchases' !== ($data['type'] ?? null)) {
            throw JsonApiException::conflict('The resource type must be "purchases".', '/data/type');
        }

        $attributes = $data['attributes'] ?? null;
        if (!\is_array($attributes) || array_is_list($attributes)) {
            throw JsonApiException::validation(['/data/attributes' => 'The attributes must be an object.']);
        }

        $rawLines = $attributes['lines'] ?? null;
        if (!\is_array($rawLines) || !array_is_list($rawLines) || [] === $rawLines) {
            throw JsonApiException::validation(['/data/attributes/lines' => 'At least one line is required.']);
        }

        $errors = [];
        $lines = [];
        foreach ($rawLines as $index => $line) {
            $pointer = "/data/attributes/lines/$index";

            if (!\is_array($line) || array_is_list($line)) {
                $errors[$pointer] = 'A line must be an object with bookId and quantity.';
                continue;
            }

            $valid = true;
            if (!\is_int($line['bookId'] ?? null) || $line['bookId'] < 1) {
                $errors["$pointer/bookId"] = 'The bookId must be an integer.';
                $valid = false;
            }
            if (!\is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                $errors["$pointer/quantity"] = 'The quantity must be an integer of at least 1.';
                $valid = false;
            }

            if ($valid) {
                $lines[] = ['index' => $index, 'bookId' => $line['bookId'], 'quantity' => $line['quantity']];
            }
        }

        if ([] !== $errors) {
            throw JsonApiException::validation($errors);
        }

        $bought = $this->purchases->buy($customerId, $lines);
        $purchase = Presenter::purchase($bought['purchase'], $bought['lines']);

        return JsonApi::response(
            ['data' => $purchase, 'links' => ['self' => $purchase['links']['self']]],
            Response::HTTP_CREATED,
            ['Location' => $purchase['links']['self']],
        );
    }
}
