<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ecosystem\ApplyMembershipChange;
use App\Http\Controllers\Controller;
use App\Services\Integrations\SignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * InfoDot tells this app, signed, that a company's membership changed (the same endpoint Dot.Files and Dot.docs
 * expose). The signature is HMAC-SHA256 over "{timestamp}.{raw body}" with this app's INFODOT_SERVICE_SECRET (the
 * one it redeems handoff tokens with); the timestamp must be fresh. Every refusal looks the same.
 */
class EcosystemMembershipController extends Controller
{
    /** A membership event is a few hundred bytes; anything near this is not one. */
    private const MAX_BODY_BYTES = 16384;

    public function __invoke(Request $request, ApplyMembershipChange $apply): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        if (! SignedRequest::valid($request, config('ecosystem.infodot.secret'), (int) config('integrations.infodot.tolerance_seconds', 300))) {
            Log::warning('Membership webhook refused.', ['reason' => 'not configured, or bad or stale signature']);

            return response()->json(['error' => 'invalid_request'], 401);
        }

        $change = Validator::make((array) json_decode($request->getContent(), true), [
            'event' => ['required', 'in:member.removed,member.role_changed,team.deleted'],
            'team_uuid' => ['required', 'uuid'],
            'email' => ['nullable', 'email'],
            'role' => ['nullable', 'string', 'max:40'],
        ]);

        if ($change->fails()) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        // A captured delivery replayed inside the five-minute window must not be applied twice: "role changed to admin" replayed
        // after a later demotion would otherwise hand the role back. The same signed delivery is acknowledged and ignored.
        // (QA security D-1403). A delivery that failed is forgotten, so InfoDot's own retry still goes through.
        $seen = 'infodot-membership:'.hash('sha256', (string) $request->header('X-InfoDot-Timestamp').'.'.$request->getContent());
        $window = max(60, (int) config('integrations.infodot.tolerance_seconds', 300)) * 2;

        if (! Cache::add($seen, 1, $window)) {
            return response()->json(['ok' => true], 202);
        }

        try {
            $apply($change->validated());
        } catch (\Throwable $e) {
            Cache::forget($seen);

            throw $e;
        }

        return response()->json(['ok' => true], 202);
    }
}
