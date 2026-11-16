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

    // The selling entity: the company ledger in Finance (by its InfoDot uuid) whose books receive the licence invoices. Unset = no
    // invoices are made (payments are still recorded and confirmed). VAT follows that ledger's own business profile; nothing is assumed.
    'seller' => [
        'team_uuid' => env('LICENCE_SELLER_TEAM_UUID'),
        'user_email' => env('LICENCE_SELLER_USER_EMAIL'), // who the books say issued it; default the ledger's owner
        'cash_account_id' => env('LICENCE_CASH_ACCOUNT_ID'), // the ledger's Paystack clearing account; unset = invoices stay open
    ],

    // What Finance tells InfoDot's read pull (GET /api/entitlements/{team_uuid}) is seat_limit = included + licensed quantity.
    'included_seats' => (int) env('LICENCE_INCLUDED_SEATS', 1),

    // Reconciliation only (read-only): where to ask InfoDot what it holds per company. {team_uuid} is replaced. Unset = Finance-side checks only.
    'reconcile' => [
        'infodot_url' => env('LICENCE_RECONCILE_INFODOT_URL'),
        'timeout' => 8,
        'period_tolerance_seconds' => 86400,
    ],

    // 7 days on a first purchase (D10, legal review pending).
    'refund_window_days' => (int) env('LICENCE_REFUND_WINDOW_DAYS', 7),

    // Finance -> InfoDot confirmation (R-50 endpoint, kind "licence_batch").
    'push' => [
        'url' => env('LICENCE_PUSH_URL'),
        'secret' => env('LICENCE_PUSH_SECRET', env('PLATFORM_SUBSCRIPTION_CALLBACK_SECRET')),
        'timeout' => (int) env('LICENCE_PUSH_TIMEOUT', 8),
        'max_attempts' => (int) env('LICENCE_PUSH_MAX_ATTEMPTS', 12),
    ],
];
