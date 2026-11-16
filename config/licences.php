<?php

/*
|--------------------------------------------------------------------------
| R-53 Licence billing (InfoDot <-> Dot.Finance), contract: r53/CONTRACT.md
|--------------------------------------------------------------------------
| InfoDot calls Finance signed (HMAC-SHA256 over "{timestamp}.{raw body}", 300 s) and Finance confirms back to InfoDot
| signed with the R-50 callback secret. Finance never takes a price or quantity from a browser. Inert until set.
*/

return [
    // Shared secret InfoDot signs its calls to Finance with (the entitlement secret).
    'infodot_secret' => env('LICENCE_INFODOT_SECRET', env('FINANCE_ENTITLEMENT_SECRET')),
    'tolerance_seconds' => 300,
    'max_body_bytes' => 16384,
    'currency' => 'ZAR',
    'max_quantity' => 1000,

    // Where Paystack sends the buyer after paying (an InfoDot page). Never carries anything that grants value.
    'return_url' => env('LICENCE_CHECKOUT_RETURN_URL'),

    // Finance -> InfoDot confirmation (R-50 endpoint, kind "licence_batch").
    'push' => [
        'url' => env('LICENCE_PUSH_URL'),
        'secret' => env('LICENCE_PUSH_SECRET', env('PLATFORM_SUBSCRIPTION_CALLBACK_SECRET')),
        'timeout' => (int) env('LICENCE_PUSH_TIMEOUT', 8),
        'max_attempts' => (int) env('LICENCE_PUSH_MAX_ATTEMPTS', 12),
    ],
];
