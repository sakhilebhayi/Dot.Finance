<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Licences\LicenceBatch;
use App\Services\Licences\LicenceSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/entitlements/{team_uuid}: what Finance holds for one InfoDot company, for InfoDot's existing R-13 read (kept for
 * reconciliation only; InfoDot's ledger is driven by the pushed confirmations). Signed, GET signs the empty body. Read-only.
 */
class LicenceEntitlementController extends Controller
{
    public function __invoke(Request $request, string $teamUuid): JsonResponse
    {
        if (! LicenceSignature::valid($request, config('licences.infodot_secret'), (int) config('licences.tolerance_seconds', 300))) {
            return response()->json(['error' => 'invalid_request'], 401);
        }

        $batches = LicenceBatch::query()->where('team_uuid', strtolower($teamUuid))->orderBy('id')->get();

        if ($batches->isEmpty()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $licensed = $batches->filter(fn (LicenceBatch $b) => $b->first_paid_at !== null && $b->period_end?->isFuture()
            && in_array($b->status, [LicenceBatch::ACTIVE, LicenceBatch::PAST_DUE, LicenceBatch::CANCELLED], true))->sum('quantity');

        return response()->json([
            'team_uuid' => strtolower($teamUuid),
            'status' => $licensed > 0 ? 'active' : 'none',
            'licensed_quantity' => (int) $licensed,
            'seat_limit' => (int) config('licences.included_seats', 1) + (int) $licensed,
            'batches' => $batches->map(fn (LicenceBatch $b) => ['batch_uuid' => $b->batch_uuid, 'status' => $b->status, 'quantity' => $b->quantity, 'interval' => $b->interval_raw, 'period_end' => $b->period_end?->utc()->toIso8601String()])->all(),
        ]);
    }
}
