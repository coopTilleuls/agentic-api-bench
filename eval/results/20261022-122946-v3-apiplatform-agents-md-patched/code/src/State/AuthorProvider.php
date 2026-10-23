<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Http\ListParams;
use App\Service\Catalog;

/**
 * @implements ProviderInterface<object>
 */
final class AuthorProvider implements ProviderInterface
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $params = ListParams::fromRequest($context['request'], [], ['books']);

        if (!isset($uriVariables['id'])) {
            $page = $this->catalog->findAuthorsPage($params->number, $params->size);
            $this->catalog->attachPublishedBooks(iterator_to_array($page));

            return $page;
        }
        if (!ctype_digit((string) $uriVariables['id'])) {
            return null;
        }

        $author = $this->catalog->findAuthor((int) $uriVariables['id']);
        if (null !== $author) {
            $this->catalog->attachPublishedBooks([$author]);
        }

        return $author;
    }
}
