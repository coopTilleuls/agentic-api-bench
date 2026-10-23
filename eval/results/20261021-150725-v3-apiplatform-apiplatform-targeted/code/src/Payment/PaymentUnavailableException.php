<?php

namespace App\Payment;

final class PaymentUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The payment service is unavailable.');
    }
}
