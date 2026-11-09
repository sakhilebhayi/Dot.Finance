<?php

namespace App\Services\Bank;

use App\Support\ImportFingerprint;

/**
 * A stable identity for one statement line, so importing the same statement again, or a longer one that contains it,
 * cannot double count. A line the bank itself numbered (an OFX FITID) is identified by that number together with its date and amount; every other line by
 * its account, date, signed amount and normalised description, with an occurrence counter so two identical rows on one
 * day are two lines and a re-import recognises both.
 */
final class StatementFingerprint
{
    public static function make(int $accountId, string $date, int $signedCents, string $description, int $occurrence, ?string $externalId = null): string
    {
        if ($externalId !== null && $externalId !== '') {
            // the date and amount ride along because some banks reuse an id: only a line that agrees on all three is the same line
            return hash('sha256', implode('|', ['ext', $accountId, $externalId, $date, $signedCents]));
        }

        return hash('sha256', implode('|', ['line', $accountId, $date, $signedCents, ImportFingerprint::normalise($description), $occurrence]));
    }
}
