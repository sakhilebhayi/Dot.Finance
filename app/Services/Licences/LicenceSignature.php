<?php

namespace App\Services\Licences;

use Illuminate\Http\Request;

/**
 * Verifies an InfoDot call: HMAC-SHA256 over "{timestamp}.{raw body}" sent as "sha256=<hex>", with the unix time in a
 * timestamp header, inside a 300 s window. The contract names X-Dot-Signature; every other Dot call uses X-InfoDot-Signature
 * with X-InfoDot-Timestamp. Both header families are accepted so either InfoDot implementation verifies. No secret = off.
 */
final class LicenceSignature
{
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function valid(Request $request, ?string $secret, int $tolerance = 300): bool
    {
        $secret = (string) $secret;
        $timestampHeader = (string) ($request->header('X-InfoDot-Timestamp') ?: $request->header('X-Dot-Timestamp'));
        $signature = (string) ($request->header('X-InfoDot-Signature') ?: $request->header('X-Dot-Signature'));

        $timestamp = preg_match('/^\d{1,12}$/', $timestampHeader) === 1 ? (int) $timestampHeader : 0;
        $key = $secret !== '' ? $secret : hash('sha256', 'dot-finance-unconfigured-licences');
        $given = str_starts_with($signature, 'sha256=') ? $signature : 'sha256='.$signature;

        $matches = hash_equals(self::sign($key, $timestamp, $request->getContent()), $given);
        $fresh = $timestamp !== 0 && abs(time() - $timestamp) <= $tolerance;

        return $secret !== '' && $matches && $fresh;
    }
}
