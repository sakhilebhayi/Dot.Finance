<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    | The policy is ENFORCED by default (nonce per request, no inline style, no third-party origin). Set
    | SECURITY_CSP_REPORT_ONLY=true in .env to make the same policy report-only while diagnosing a breakage on a
    | host, without a deploy. Violations are posted to /csp-report and logged (directive and host only).
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

    /*
    | Strict-Transport-Security max-age in seconds (HTTPS requests only). 0 switches it off. Six months, no
    | includeSubDomains and no preload: sibling Dot platforms live on neighbouring subdomains and each sets its own.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),

];
