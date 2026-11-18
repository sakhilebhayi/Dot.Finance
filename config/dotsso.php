<?php

use App\Sso\FinanceProvisioner;

/*
 * Dot.Finance's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env. The session guard is the package
 * default (`web`), which matters here: the signed-in routes use `auth:sanctum`.
 */
return [
    'provisioner' => FinanceProvisioner::class,

    // Finance has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link is only ever a same-origin path; it may open any signed-in page. A link that names a company ledger
    // (?context=<id>) is not kept: the OIDC sign-in does not carry company membership, so the ledger may not exist for the person
    // yet, and Finance answers an unusable context with a 404. Such a link lands on the Command centre instead; ?context=personal
    // is kept. Machine and sign-in doors can never be named.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => [
            '#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|api|csp-report|up|attachments|exports)(/|\?|$)#i',
            '#[?&]context=(?!personal(&|$|\#))#i',
        ],
    ],

    // Nothing extra is exempt. /api/* (membership push, Emall sales, payroll import, Sanctum), signed URLs, /csp-report and /up are
    // machine paths the package never intercepts.
    'allow_paths' => [],
];
