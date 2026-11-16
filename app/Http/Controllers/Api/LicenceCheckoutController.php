<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Licences\LicenceCheckout;
use App\Services\Licences\LicenceSignature;
use App\Services\Paystack\PaystackException;
use App\Services\Paystack\PaystackGateway;
use App\Services\Paystack\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/licences/checkout (R-53 contract). Server to server from InfoDot. Order: size cap (413), signature (401, the
 * same generic answer for every cause), feature switch (503 without a Paystack key), structure (422), then the checkout.
 * The amount, quantity and interval come only from the signed body; nothing a browser sent ever reaches here.
 * 200 {checkout_url, ...}; 409 when the batch_uuid exists with different terms; 502 when Paystack could not start it (retry).
 */
class LicenceCheckoutController extends Controller
{
    public function __invoke(Request $request, LicenceCheckout $checkout, PaystackGateway $paystack): JsonResponse
    {
        if (strlen($request->getContent()) > (int) config('licences.max_body_bytes', 16384)) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        if (! LicenceSignature::valid($request, config('licences.infodot_secret'), (int) config('licences.tolerance_seconds', 300))) {
            Log::warning('Licence checkout refused.', ['reason' => 'not configured, or bad or stale signature']);

            return response()->json(['error' => 'invalid_request'], 401);
        }

        if (! $paystack->enabled()) {
            return response()->json(['error' => 'payments_unavailable'], 503);
        }

        $body = json_decode($request->getContent(), true);
        $valid = Validator::make(is_array($body) && ! array_is_list($body) ? $body : [], [
            'batch_uuid' => ['required', 'uuid'],
            'team_uuid' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.(int) config('licences.max_quantity', 1000)],
            'interval' => ['required', 'string', 'in:month,monthly,year,yearly,annual,annually'],
            'total_minor' => ['required', 'integer', 'min:1', 'max:2000000000'],
            'currency' => ['required', 'string', 'in:'.config('licences.currency', 'ZAR')],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:200'],
            'customer.email' => ['required', 'email', 'max:200'],
        ]);

        if ($valid->fails()) {
            return response()->json(['error' => 'invalid_request', 'errors' => $valid->errors()->keys()], 422);
        }

        $in = $valid->validated();
        $in['batch_uuid'] = strtolower($in['batch_uuid']);
        $in['team_uuid'] = strtolower($in['team_uuid']);
        $in['interval'] = strtolower($in['interval']);
        PlanRegistry::interval($in['interval']);

        try {
            $result = $checkout->start($in);
        } catch (PaystackException $e) {
            Log::warning('Licence checkout could not be started at Paystack.', ['batch_uuid' => $in['batch_uuid'], 'status' => $e->httpStatus, 'transient' => $e->transient]);

            return response()->json(['error' => 'provider_unavailable'], 502);
        }

        if ($result['outcome'] === 'conflict') {
            return response()->json(['error' => 'batch_conflict'], 409);
        }

        $batch = $result['batch'];

        return response()->json(['checkout_url' => $batch->checkout_url, 'batch_uuid' => $batch->batch_uuid, 'status' => $batch->status, 'replayed' => $result['outcome'] === 'replayed']);
    }
}
