<?php

/*
|--------------------------------------------------------------------------
| R-44 Integrations
|--------------------------------------------------------------------------
| Every connection is inert until its settings are present, and every inbound call is signed. See
| docs/integrations/README.md for what is connected, what is contract-only and what is not possible, and why.
*/

return [

    // InfoDot tells this app, signed with the same service secret the sign-in redeem uses, that a company's
    // membership changed (POST /api/ecosystem/membership). Inert while INFODOT_SERVICE_SECRET is empty.
    'infodot' => [
        'tolerance_seconds' => 300,
    ],

    // Dot.Emall: no API exists yet, so a signed push is DEFINED here (docs/integrations/emall-sales-contract.md) and
    // refused until FINANCE_EMALL_SECRET is set. Emall signs with HMAC-SHA256 over "{timestamp}.{raw body}".
    'emall' => [
        'secret' => env('FINANCE_EMALL_SECRET'),
        'tolerance_seconds' => 300,
        'max_lines_per_delivery' => 500,
        'max_body_bytes' => 524288,
    ],

    // Dot.HR: no payroll tables or API exist. Only the contract is defined (docs/integrations/hr-payroll-contract.md).
    'hr' => [
        'secret' => env('FINANCE_HR_SECRET'),
    ],

    // Dot.Analytics daily aggregate snapshot (the Pulse/Auction publisher shape). Needs all three values.
    'analytics' => [
        'url' => env('ANALYTICS_INGEST_URL'),
        'secret' => env('ANALYTICS_INGEST_SECRET'),
        'platform' => env('ANALYTICS_PLATFORM_KEY', 'finance'),
        'timeout' => (int) env('ANALYTICS_TIMEOUT', 5),
        'breaker' => ['threshold' => 3, 'cooldown' => 300],
    ],

    // Dot.Notify inbound webhook (POST /webhooks/{token}, X-Dot-Signature = HMAC-SHA256 of the raw body with the
    // webhook's signing secret). The URL carries the endpoint token. Inert unless both are set.
    'notify' => [
        'url' => env('NOTIFY_WEBHOOK_URL'),
        'secret' => env('NOTIFY_WEBHOOK_SECRET'),
        'timeout' => (int) env('NOTIFY_TIMEOUT', 5),
    ],

];
