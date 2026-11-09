<?php

/*
|--------------------------------------------------------------------------
| R-44 Intelligence
|--------------------------------------------------------------------------
| The assistant answers from the ledger with fixed rules. The optional model layer is OFF by default; when it is on
| it may only (a) pick which of the fixed intents a question matches and (b) reword an answer that was already
| computed, and its wording is thrown away if it contains any number that is not in the computed facts.
| Turning it on sends the computed answer (figures, party and category names) to the provider named in
| config/services.php; nothing else leaves the server. See docs/R44_INTEL.md.
*/

return [

    'model' => [
        'enabled' => (bool) env('FINANCE_AI_ENABLED', false),
        'timeout' => (int) env('FINANCE_AI_TIMEOUT', 12),
        'max_tokens' => (int) env('FINANCE_AI_MAX_TOKENS', 400),
    ],

    'assistant' => [
        // Questions per person per minute, and the draft lifetime in hours.
        'per_minute' => (int) env('FINANCE_ASSISTANT_PER_MINUTE', 12),
        'draft_hours' => (int) env('FINANCE_ASSISTANT_DRAFT_HOURS', 24),
        'history_limit' => 30,
    ],

    'detectors' => [
        // A month's spend in a category is unusual when it is further than this many scaled MADs above the median
        // of the earlier months, and also well above that median (so a steady category is not flagged for a few rand).
        'spike_mad_threshold_tenths' => 35, // 3.5, kept as an integer: no floats near money
        'spike_min_ratio_bp' => 15000, // 150% of the median
        'spike_min_cents' => 50000,    // and at least R500.00 above it
        'spike_min_history_months' => 4,
        'duplicate_window_days' => 3,
        'round_amount_cents' => 100000, // multiples of R1,000.00
        'round_min_cents' => 500000,    // that are at least R5,000.00
        'dormant_days' => 120,
        'dormant_min_invoices' => 3,
        'concentration_share_bp' => 5000,   // one customer above 50% of revenue
        'concentration_min_months' => 3,
        'margin_drop_points_bp' => 1500,    // 15 percentage points below the earlier median
        'runway_critical_months' => 1,
        'runway_warning_months' => 3,
        'bank_detail_fields' => ['bank_account', 'bank_name', 'branch_code', 'bank_details', 'iban', 'account_number'],
    ],

    'notifications' => [
        // E-mail is sent from the app mailer to a person who belongs to the ledger, never to a customer or supplier.
        'email_enabled' => (bool) env('FINANCE_NOTIFY_EMAIL', true),
        'approval_waiting_after_hours' => 1,
    ],

];
