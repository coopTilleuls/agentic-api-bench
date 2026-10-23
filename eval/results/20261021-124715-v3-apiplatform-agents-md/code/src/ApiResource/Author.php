<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Controller\AuthorList;
use App\Controller\AuthorShow;

#[ApiResource(
    shortName: 'Author',
    operations: [
        new GetCollection(uriTemplate: '/authors', controller: AuthorList::class),
        new Get(uriTemplate: '/authors/{id}', controller: AuthorShow::class),
    ],
)]
final class Author
{
    #[ApiProperty(identifier: true)]
    public int $id;
}
