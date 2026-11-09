<?php

namespace App\Services\Tax\Exceptions;

/** A return cannot be prepared yet; reasons() lists what blocks it. */
final class NotReady extends TaxException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('This return is not ready to prepare: '.implode(' ', $reasons));
    }

    /** @return list<string> */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
