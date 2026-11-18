<?php

namespace App\Services\Paystack;

use RuntimeException;

/** Paystack refused, could not be reached, or the feature is off. The message never carries a key or card data. */
class PaystackException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
