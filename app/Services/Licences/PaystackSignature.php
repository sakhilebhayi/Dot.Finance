<?php

namespace App\Services\Licences;

/**
 * Paystack signs the RAW request body with HMAC-SHA512 keyed by the merchant secret key and sends the hex digest in
 * x-paystack-signature. Compared in constant time. No key configured means nothing verifies.
 */
final class PaystackSignature
{
    public static function valid(string $rawBody, ?string $header, ?string $secretKey): bool
    {
        $secret = (string) $secretKey;
        $key = $secret !== '' ? $secret : hash('sha256', 'dot-finance-unconfigured-paystack');
        $expected = hash_hmac('sha512', $rawBody, $key);

        return hash_equals($expected, strtolower((string) $header)) && $secret !== '';
    }

    public static function sign(string $rawBody, string $secretKey): string
    {
        return hash_hmac('sha512', $rawBody, $secretKey);
    }
}
