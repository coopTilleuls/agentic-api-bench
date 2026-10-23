<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\Http\ListParams;
use App\Service\Catalog;

/**
 * @implements ProviderInterface<object>
 */
final class BookProvider implements ProviderInterface
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $params = ListParams::fromRequest($context['request'], ['priceCents', 'title', 'publishedAt'], ['author']);

        if (!isset($uriVariables['id'])) {
            return $this->catalog->findBooksPage($params);
        }
        if (!ctype_digit((string) $uriVariables['id'])) {
            return null;
        }

        // A PATCH may change an unpublished book, every other read hides it.
        return $this->catalog->findBook((int) $uriVariables['id'], $operation instanceof Patch);
    }
}
