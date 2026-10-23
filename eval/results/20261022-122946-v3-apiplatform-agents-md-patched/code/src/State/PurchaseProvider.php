<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProviderInterface;
use App\Http\ListParams;
use App\Service\Catalog;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<object>
 */
final class PurchaseProvider implements ProviderInterface
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof Post) {
            return null;
        }

        /** @var Request $request */
        $request = $context['request'];
        $customerId = (int) $request->attributes->get('_customer_id');
        $params = ListParams::fromRequest($request, [], []);

        if (!isset($uriVariables['id'])) {
            return $this->catalog->findPurchasesPage($customerId, $params);
        }
        if (!ctype_digit((string) $uriVariables['id'])) {
            return null;
        }

        return $this->catalog->findPurchase($customerId, (int) $uriVariables['id']);
    }
}
