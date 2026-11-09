<?php

namespace App\Services\Purchases;

use RuntimeException;

/**
 * A refusal the person can act on. The message is written for them (controllers show it as is); `code` is for tests and callers.
 */
class PurchasesException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'refused')
    {
        parent::__construct($message);
    }
}
