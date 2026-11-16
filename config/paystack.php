<?php

/*
|--------------------------------------------------------------------------
| R-53 Paystack (licence billing)
|--------------------------------------------------------------------------
| Paystack is the card processor behind InfoDot's paid user licences. No key means the whole feature is off: the checkout
| answers 503 and the webhook refuses everything. The key lives in the environment only; it is never committed or logged.
*/

return [
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    'timeout' => (int) env('PAYSTACK_TIMEOUT', 15),
];
