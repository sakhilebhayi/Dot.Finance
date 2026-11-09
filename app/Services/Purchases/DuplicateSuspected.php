<?php

namespace App\Services\Purchases;

use Illuminate\Support\Collection;

/** A bill looks like one already on file. Carries the matches so the screen can show them and ask for a confirmation. */
class DuplicateSuspected extends PurchasesException
{
    /** @param Collection<int, array{bill: \App\Models\Purchases\Bill, reason: string, label: string}> $matches */
    public function __construct(public readonly Collection $matches)
    {
        parent::__construct('This looks like a bill that is already on file: '.$matches->pluck('label')->implode(' ').' Check it, and confirm if it really is a different bill.', 'duplicate');
    }
}
