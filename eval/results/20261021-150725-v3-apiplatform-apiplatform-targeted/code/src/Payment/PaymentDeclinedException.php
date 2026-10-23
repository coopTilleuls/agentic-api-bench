<?php

namespace App\Payment;

final class PaymentDeclinedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The payment was declined.');
    }
}
