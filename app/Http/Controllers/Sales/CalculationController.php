<?php

namespace App\Http\Controllers\Sales;

use App\Models\Sales\SalesDocument;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\FxMath;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The editor's live preview. The browser sends what was typed; this answers with every figure, worked out by the
 * same code that will post the document. Nothing is saved and nothing the browser sends is a total.
 */
class CalculationController extends SalesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('create', SalesDocument::class);

        try {
            $data = $request->validate($this->headerRules() + ['kind' => $this->kindRule(), 'document_id' => ['nullable', 'integer']]);
            [$header, $lines] = $this->documentInput($data);
            $existing = isset($data['document_id']) ? SalesDocument::query()->find($data['document_id']) : null;
            if ($existing !== null) {
                $this->authorize('update', $existing);
            }

            $preview = app(DocumentDrafts::class)->preview($this->ledger(), $header, $lines, $existing);
        } catch (ValidationException $e) {
            // an unfinished form is a normal state while someone types, not an error: 200 with the reason, so nothing is logged as a failed request
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first()]);
        } catch (SalesViolation $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        $doc = $preview['document'];
        $money = fn (int $cents, string $ccy) => Money::format($cents, $ccy);
        $ccy = $doc['currency'];
        $base = $doc['base_currency'];

        return response()->json([
            'ok' => true,
            'currency' => $ccy,
            'lines' => array_map(fn ($l) => ['net' => $money($l['net_cents'], $ccy), 'tax' => $money($l['tax_cents'], $ccy), 'gross' => $money($l['gross_cents'], $ccy), 'rate' => $l['rate_bp'] ? rtrim(rtrim(number_format($l['rate_bp'] / 100, 2, '.', ''), '0'), '.').'%' : ''], $doc['lines']),
            'summary' => array_map(fn ($s) => ['label' => ($s['tax_code'] ?? 'No tax').($s['rate_bp'] ? ' at '.rtrim(rtrim(number_format($s['rate_bp'] / 100, 2, '.', ''), '0'), '.').'%' : ''), 'net' => $money($s['net_cents'], $ccy), 'tax' => $money($s['tax_cents'], $ccy)], $doc['summary']),
            'totals' => ['net' => $money($doc['net_cents'], $ccy), 'discount' => $money($doc['discount_cents'], $ccy), 'tax' => $money($doc['tax_cents'], $ccy), 'total' => $money($doc['total_cents'], $ccy)],
            'base' => $ccy !== $base ? ['total' => $money($doc['base_total_cents'], $base), 'rate' => FxMath::formatRate((int) $doc['fx_rate_e8']), 'as_of' => $doc['fx_as_of'], 'source' => $doc['fx_source'], 'currency' => $base] : null,
            'due_date' => $preview['due_date'], 'valid_until' => $preview['valid_until'],
            'checklist' => ['applies' => $preview['checklist']['applies'], 'valid' => $preview['checklist']['valid'], 'items' => $preview['checklist']['items']],
            'warnings' => $preview['warnings'],
            'label' => ['calculated' => 'Calculated by the server', 'confidence' => $doc['confidence'], 'rounding' => $doc['rounding_level']],
        ]);
    }
}
