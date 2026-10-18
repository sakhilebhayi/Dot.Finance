<?php

namespace App\Support;

/**
 * A stable identity for one imported row, so re-importing the same statement
 * (or a longer one that contains it) cannot double count.
 *
 * It hashes the account, the date, the signed amount in cents and the
 * description with case and spacing normalised. $occurrence is "this is the Nth
 * identical row in the file": two real coffees on one day at one price are two
 * transactions, and a re-import recognises both.
 */
class ImportFingerprint
{
    public static function make(int $accountId, string $date, int $signedCents, string $description, int $occurrence): string
    {
        return hash('sha256', implode('|', [$accountId, $date, $signedCents, self::normalise($description), $occurrence]));
    }

    public static function normalise(string $description): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($description)));
    }
}
