<?php

namespace App\Services\Integrations;

use Illuminate\Http\Request;

/**
 * Is this server-to-server call signed with the shared secret, and fresh? The scheme every Dot platform uses for
 * signed calls: HMAC-SHA256 over "{timestamp}.{raw body}", sent as "sha256=<hex>" in X-InfoDot-Signature with the
 * unix time in X-InfoDot-Timestamp, and a timestamp within the tolerance either way (a captured request goes stale).
 *
 * The HMAC is always computed, even for a missing secret or header, so a refusal costs the same whatever was wrong.
 * An empty secret means the integration is switched off: nothing authenticates.
 */
final class SignedRequest
{
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function valid(Request $request, ?string $secret, int $tolerance = 300): bool
    {
        $secret = (string) $secret;
        $timestampHeader = (string) $request->header('X-InfoDot-Timestamp');
        $signature = (string) $request->header('X-InfoDot-Signature');

        $timestamp = preg_match('/^\d{1,12}$/', $timestampHeader) === 1 ? (int) $timestampHeader : 0;

        $key = $secret !== '' ? $secret : hash('sha256', 'dot-finance-unconfigured-integration');
        $expected = self::sign($key, $timestamp, $request->getContent());
        $given = str_starts_with($signature, 'sha256=') ? $signature : 'sha256='.$signature;

        $matches = hash_equals($expected, $given);
        $fresh = $timestamp !== 0 && abs(time() - $timestamp) <= $tolerance;

        return $secret !== '' && $matches && $fresh;
    }
}
