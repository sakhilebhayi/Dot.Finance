<?php

use App\Http\Controllers\Api\EcosystemMembershipController;
use App\Http\Controllers\Api\EmallSalesController;
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
