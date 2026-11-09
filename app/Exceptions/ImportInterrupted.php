<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A large CSV import is committed in chunks, each chunk all or nothing. If a later chunk fails, the earlier ones are already
 * saved; this says how many, so the page can tell the person exactly that. Importing the same file again skips every row
 * already imported (each row carries a fingerprint with a unique index), so the rest can simply be imported.
 */
class ImportInterrupted extends RuntimeException
{
    public function __construct(public readonly int $imported, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
