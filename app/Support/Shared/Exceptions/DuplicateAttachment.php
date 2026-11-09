<?php

namespace App\Support\Shared\Exceptions;

use App\Models\Shared\Attachment;
use RuntimeException;

/** The same file (same sha256) is already stored in this ledger. */
final class DuplicateAttachment extends RuntimeException
{
    public function __construct(public readonly Attachment $existing)
    {
        parent::__construct('This file is already stored in this ledger (uploaded as "'.$existing->original_name.'" on '.$existing->created_at?->toDateString().').');
    }
}
