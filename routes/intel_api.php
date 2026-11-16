<?php

use App\Http\Controllers\Api\EcosystemMembershipController;
use App\Http\Controllers\Api\EmallSalesController;
use App\Http\Controllers\Api\LicenceCheckoutController;
use App\Http\Controllers\Api\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Signed server-to-server endpoints (prefix /api). No session, no user, no CSRF: each call proves itself with
| an HMAC signature over the timestamp and the raw body, and a refusal is always the same generic 401.
|--------------------------------------------------------------------------
| Required from routes/api.php. Registers routes only (route:cache).
*/

// InfoDot -> this app: a company's membership changed (the same endpoint Dot.Files and Dot.docs expose).
Route::post('/ecosystem/membership', EcosystemMembershipController::class)->middleware('throttle:60,1')->name('api.ecosystem.membership');

// Dot.Emall -> this app: a delivery of seller sale lines (contract: docs/integrations/emall-sales-contract.md).
Route::post('/integrations/emall/sales', EmallSalesController::class)->middleware('throttle:60,1')->name('api.integrations.emall.sales');

// InfoDot -> this app: start a licence batch checkout (R-53, signed; contract r53/CONTRACT.md).
Route::post('/licences/checkout', LicenceCheckoutController::class)->middleware('throttle:60,1')->name('api.licences.checkout');

// Paystack -> this app: licence billing events (R-53). Verified by x-paystack-signature (HMAC-SHA512 of the raw body); not a signed Dot call.
Route::post('/paystack/webhook', PaystackWebhookController::class)->middleware('throttle:240,1')->name('api.paystack.webhook');
