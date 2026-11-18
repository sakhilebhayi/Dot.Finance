<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Licences\LicenceCancel;
use App\Services\Licences\LicenceSignature;
use App\Services\Paystack\PaystackException;
use App\Services\Paystack\PaystackGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/licences/cancel (R-53). Signed exactly like checkout. Body {batch_uuid, team_uuid, mode: "at_period_end"}.
 * 200 {status, replayed}; 401 bad/stale signature; 404 unknown batch (also for another company's batch); 409 subscription not
 * ready to disable yet (retry); 422 structure; 502 Paystack could not disable it (nothing changed, retry); 503 payments off.
 */
class LicenceCancelController extends Controller
{
    public function __invoke(Request $request, LicenceCancel $cancel, PaystackGateway $paystack): JsonResponse
    {
        if (strlen($request->getContent()) > (int) config('licences.max_body_bytes', 16384)) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        if (! LicenceSignature::valid($request, config('licences.infodot_secret'), (int) config('licences.tolerance_seconds', 300))) {
            Log::warning('Licence cancel refused.', ['reason' => 'not configured, or bad or stale signature']);

            return response()->json(['error' => 'invalid_request'], 401);
        }

        if (! $paystack->enabled()) {
            return response()->json(['error' => 'payments_unavailable'], 503);
        }

        $body = json_decode($request->getContent(), true);
        $valid = Validator::make(is_array($body) && ! array_is_list($body) ? $body : [], [
            'batch_uuid' => ['required', 'uuid'],
            'team_uuid' => ['required', 'uuid'],
            'mode' => ['required', 'string', 'in:at_period_end'],
        ]);

        if ($valid->fails()) {
            return response()->json(['error' => 'invalid_request', 'errors' => $valid->errors()->keys()], 422);
        }

        $in = $valid->validated();

        try {
            $result = $cancel->cancel(strtolower($in['batch_uuid']), strtolower($in['team_uuid']));
        } catch (PaystackException $e) {
            Log::warning('Licence cancel could not reach Paystack.', ['batch_uuid' => $in['batch_uuid'], 'status' => $e->httpStatus, 'transient' => $e->transient]);

            return response()->json(['error' => 'provider_unavailable'], 502);
        }

        return match ($result['outcome']) {
            'unknown' => response()->json(['error' => 'unknown_batch'], 404),
            'not_ready' => response()->json(['error' => 'subscription_not_ready'], 409),
            default => response()->json(['batch_uuid' => $result['batch']->batch_uuid, 'status' => $result['batch']->status, 'replayed' => $result['outcome'] === 'replayed']),
        };
    }
}
