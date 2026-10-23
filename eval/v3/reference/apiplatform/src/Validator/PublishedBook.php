<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PublishedBook extends Constraint
{
    public string $message = 'This book does not exist.';
}
