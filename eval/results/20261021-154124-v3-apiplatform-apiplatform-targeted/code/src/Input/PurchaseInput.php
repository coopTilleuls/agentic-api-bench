<?php

namespace App\Input;

/**
 * Body of POST /purchases. The lines are checked by PurchaseProcessor, which
 * reports each problem with its own JSON:API pointer.
 */
final class PurchaseInput
{
    public mixed $lines = null;
}
