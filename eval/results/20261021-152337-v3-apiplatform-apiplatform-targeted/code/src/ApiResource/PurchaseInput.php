<?php

namespace App\ApiResource;

/**
 * Body of POST /purchases. Lines are untyped here so that a malformed value
 * reaches the processor, which reports the exact pointer of each invalid field.
 */
final class PurchaseInput
{
    public mixed $lines = [];
}
