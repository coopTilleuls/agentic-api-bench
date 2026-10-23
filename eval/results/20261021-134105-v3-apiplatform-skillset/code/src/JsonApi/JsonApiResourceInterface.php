<?php

namespace App\JsonApi;

/**
 * A resource object of a JSON:API document.
 */
interface JsonApiResourceInterface
{
    public function getJsonApiType(): string;

    public function getJsonApiId(): string;

    /**
     * @return array<string, mixed>
     */
    public function getJsonApiAttributes(): array;

    /**
     * Only the loaded relationships are returned.
     *
     * @return array<string, JsonApiResourceInterface|list<JsonApiResourceInterface>|null>
     */
    public function getJsonApiRelationships(): array;
}
