<?php

namespace App\JsonApi;

use Closure;
use LaravelJsonApi\Contracts\Schema\Attribute;
use LaravelJsonApi\Spec\ResourceDocument;
use LaravelJsonApi\Spec\Specification;
use LaravelJsonApi\Spec\Translator;
use LaravelJsonApi\Spec\Validators\AttributesValidator;

class IgnoreUnknownAttributesValidator extends AttributesValidator
{
    public function __construct(private readonly Specification $specification, Translator $translator)
    {
        parent::__construct($specification, $translator);
    }

    public function validate(ResourceDocument $document, Closure $next): ResourceDocument
    {
        $attributes = $document->data->attributes ?? null;

        if (is_object($attributes)) {
            $known = collect($this->specification->fields($document->type()))
                ->whereInstanceOf(Attribute::class)
                ->map(static fn (Attribute $field) => $field->name())
                ->all();

            foreach (array_keys(get_object_vars($attributes)) as $name) {
                if (!in_array($name, [...$known, 'type', 'id'], true)) {
                    unset($attributes->{$name});
                }
            }
        }

        return parent::validate($document, $next);
    }
}
