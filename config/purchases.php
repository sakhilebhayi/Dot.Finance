<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tax jurisdiction
    |--------------------------------------------------------------------------
    | Bills are taxed by the rules of this jurisdiction (docs/R44_TAX_RESEARCH.md). A ledger-level jurisdiction
    | belongs to the Tax module's financial profile; until it exists every ledger is South African.
    */
    'jurisdiction' => env('FINANCE_PURCHASES_JURISDICTION', 'ZA'),

    /*
    |--------------------------------------------------------------------------
    | Document extraction
    |--------------------------------------------------------------------------
    | `rules` reads the text of a text PDF or a text file with fixed patterns. It is always available and never needs a
    | network. `model` asks a language model to read an image or a scan: it is OFF unless you turn it on AND give it a key,
    | its answer is validated against a strict schema, and whatever it returns is a draft that a person must review.
    | Neither engine ever posts anything.
    */
    'extraction' => [
        'model_enabled' => (bool) env('FINANCE_EXTRACTION_MODEL_ENABLED', false),
        'model_api_key' => env('FINANCE_EXTRACTION_MODEL_API_KEY'),
        'model_endpoint' => env('FINANCE_EXTRACTION_MODEL_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
        'model_name' => env('FINANCE_EXTRACTION_MODEL_NAME', 'claude-sonnet-4-5'),
        'timeout_seconds' => 30,
        // the most text of a document ever read or sent anywhere
        'max_text_chars' => 20000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate detection
    |--------------------------------------------------------------------------
    */
    'duplicates' => [
        // same supplier and the same total within this many days of each other is a probable duplicate
        'fuzzy_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | State line
    |--------------------------------------------------------------------------
    | The two heavier counts on the state line (documents missing, payables out of step with the books) are cached this many
    | seconds per ledger. 0 turns the cache off.
    */
    'state_line_cache_seconds' => (int) env('FINANCE_PURCHASES_STATE_CACHE', 60),

    /*
    |--------------------------------------------------------------------------
    | Payment runs
    |--------------------------------------------------------------------------
    */
    'payment_run' => [
        'csv_disk_note' => 'A generic CSV. It is not a bank-specific import format: map the columns in your bank\'s import tool.',
    ],
];
