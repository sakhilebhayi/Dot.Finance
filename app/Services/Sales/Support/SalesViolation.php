<?php

namespace App\Services\Sales\Support;

use RuntimeException;

/**
 * A refusal a person can act on: the message is written for them and shown as is. `code` is for tests and
 * for controllers that need to react (period_closed, checklist, credit_limit, sealed, ...).
 */
class SalesViolation extends RuntimeException
{
    /** @param  array<string, mixed>  $context */
    public function __construct(string $message, public readonly string $reason = 'invalid', public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
