<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Integrations\Emall\EmallPayload;
use App\Services\Integrations\Emall\EmallSalesImport;
use App\Services\Integrations\SignedRequest;
use App\Support\Ledger\LedgerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/integrations/emall/sales: Dot.Emall pushes a delivery of sale lines for a seller business.
 *
 * Server to server; no user, no session. Order is fixed:
 *  1. body size cap (413);
 *  2. authenticate: HMAC-SHA256 over "{timestamp}.{raw body}" with FINANCE_EMALL_SECRET, fresh timestamp. A missing
 *     secret (the integration is off), a bad signature and a stale one all give the same generic 401;
 *  3. structure (422): a delivery with any malformed line is refused whole;
 *  4. tenant: company_uuid is InfoDot's id for the business and must match a company ledger here (404 otherwise,
 *     only after authentication, so a stranger learns nothing about who exists);
 *  5. import (EmallSalesImport): staged for review, never journalled.
 *
 * Responses: 201 stored, 200 replay of a delivery already received, 401, 404, 409 (same delivery id, different
 * content), 413, 422.
 */
class EmallSalesController extends Controller
{
    public function __invoke(Request $request, EmallSalesImport $import): JsonResponse
    {
        $raw = $request->getContent();

        if (strlen($raw) > (int) config('integrations.emall.max_body_bytes', 524288)) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        if (! SignedRequest::valid($request, config('integrations.emall.secret'), (int) config('integrations.emall.tolerance_seconds', 300))) {
            Log::warning('Emall sales delivery refused.', ['reason' => 'not configured, or bad or stale signature']);

            return response()->json(['error' => 'invalid_request'], 401);
        }

        $decoded = json_decode($raw, true);

        try {
            $payload = EmallPayload::validate(is_array($decoded) && ! array_is_list($decoded) ? $decoded : []);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'invalid_request', 'errors' => $e->errors()], 422);
        }

        $team = Team::where('infodot_team_uuid', strtolower($payload['company_uuid']))->first();

        if ($team === null || ! $team->isCompany()) {
            Log::warning('Emall sales delivery for an unknown company.');

            return response()->json(['error' => 'not_found'], 404);
        }

        $result = $import->import(LedgerContext::company($team), $payload, $raw);

        return match ($result['status']) {
            'conflict' => response()->json(['error' => 'delivery_conflict'], 409),
            'replayed' => response()->json($result, 200),
            default => response()->json($result, 201),
        };
    }
}
