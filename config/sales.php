<?php

use App\Services\Sales\Gateways\ManualGateway;

return [
    /*
    | Signed customer links (a read-only view of one document or statement, opened without signing in).
    */
    'share' => [
        'default_days' => (int) env('SALES_SHARE_DAYS', 30),
        'max_days' => 180,
        // requests per minute per link token and IP (see SalesServiceProvider)
        'throttle_per_minute' => (int) env('SALES_SHARE_THROTTLE', 30),
        // across every link from one address, so guessing many different tokens meets a wall too (QA security D-1407)
        'throttle_per_ip_per_minute' => (int) env('SALES_SHARE_THROTTLE_IP', 60),
    ],

    /*
    | Payment reminders: how many catch-up days the scheduled command will look back when it was not run for a while.
    */
    'reminders' => [
        'enabled' => env('SALES_REMINDERS_ENABLED', true),
        'time' => env('SALES_REMINDERS_AT', '07:30'),
    ],

    /*
    | Online payment gateways are NOT implemented. Only the manual gateway (bank details printed on the invoice,
    | payment recorded by a person) exists. A real gateway implements App\Services\Sales\Gateways\PaymentGateway
    | and is added to this list; nothing here simulates a payment.
    */
    'gateways' => [
        'manual' => ManualGateway::class,
    ],
];
