<?php

return [
    /*
    | Payroll tax summary import (Dot.HR contract). Requests are signed with HMAC-SHA256 over the raw body using this
    | shared secret, sent as "X-Dot-Signature: sha256=<hex>". With no secret configured the endpoint answers 503 and
    | accepts nothing. Dot.HR does not hold payroll today (its employee table stores no salary), so this contract is
    | defined and tested but not connected to a sender.
    */
    'payroll_import' => [
        'secret' => env('FINANCE_PAYROLL_IMPORT_SECRET'),
        'max_employees' => 2000,
        'max_age_minutes' => 15,
    ],

    // Vault retention for tax records, years. The legal basis is the rule book (records.retention_years.tax).
    'retention_fallback_years' => 5,
];
