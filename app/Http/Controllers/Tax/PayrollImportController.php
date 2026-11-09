<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Services\Tax\Exceptions\ImportRejected;
use App\Services\Tax\PayrollImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/tax/payroll-import. Signed by the sender (HMAC), no session. See PayrollImport for the contract. The signature is
 * checked on the raw body before the JSON is read; a sender can only reach the ledger its company uuid maps to.
 */
class PayrollImportController extends Controller
{
    public function __invoke(Request $request, PayrollImport $import): JsonResponse
    {
        if (! $import->isConfigured()) {
            return response()->json(['message' => 'Payroll import is not configured on this server.'], 503);
        }

        if (! $import->verifySignature($request->getContent(), $request->header('X-Dot-Signature'))) {
            return response()->json(['message' => 'The signature is missing or wrong.'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'The body is not JSON.'], 400);
        }

        try {
            $result = $import->import($payload);
        } catch (ImportRejected $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], $e->status);
        }

        return response()->json([
            'status' => $result['status'], 'run_id' => $result['run']->id, 'period' => $result['run']->period_month->format('Y-m'),
            'note' => 'Imported as a draft. A person posts the payroll journal in Dot.Finance.',
        ], $result['status'] === 'created' ? 201 : 200);
    }
}
