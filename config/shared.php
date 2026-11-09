<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | Files live on a private disk (never the public one) and are streamed through a signed,
    | ledger-checked route. The type is decided from the file's content, not its name.
    */
    'attachments' => [
        'disk' => env('FINANCE_ATTACHMENT_DISK', 'local'),
        'max_bytes' => (int) env('FINANCE_ATTACHMENT_MAX_BYTES', 15 * 1024 * 1024),
        'url_ttl_minutes' => 10,
        // detected mime => stored extension
        'mimes' => [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange-rate providers
    |--------------------------------------------------------------------------
    | Rates are entered by hand by default. The ECB reference-rate feed is the only network
    | provider, it is OFF unless FINANCE_FX_ECB_ENABLED=true, and nothing schedules it.
    */
    'fx' => [
        'ecb_enabled' => (bool) env('FINANCE_FX_ECB_ENABLED', false),
        'ecb_url' => env('FINANCE_FX_ECB_URL', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'),
        'timeout_seconds' => 10,
    ],

];
