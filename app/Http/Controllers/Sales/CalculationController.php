<?php

namespace App\Http\Controllers\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\SalesProfiles;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\FxMath;
use App\Support\Money;
use App\Support\TaxWords;
use Carbon\CarbonImmutable;
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
            'summary' => array_map(fn ($s) => ['label' => $s['tax_code'] === null ? 'No VAT' : TaxWords::label($s['tax_code'], (int) $s['rate_bp']), 'net' => $money($s['net_cents'], $ccy), 'tax' => $money($s['tax_cents'], $ccy)], $doc['summary']),
            'totals' => ['net' => $money($doc['net_cents'], $ccy), 'discount' => $money($doc['discount_cents'], $ccy), 'tax' => $money($doc['tax_cents'], $ccy), 'total' => $money($doc['total_cents'], $ccy)],
            'base' => $ccy !== $base ? ['total' => $money($doc['base_total_cents'], $base), 'rate' => FxMath::formatRate((int) $doc['fx_rate_e8']), 'as_of' => $doc['fx_as_of'], 'source' => $doc['fx_source'], 'currency' => $base] : null,
            'due_date' => $preview['due_date'], 'valid_until' => $preview['valid_until'],
            'checklist' => ['applies' => $preview['checklist']['applies'], 'valid' => $preview['checklist']['valid'], 'items' => $preview['checklist']['items']],
            'warnings' => $preview['warnings'],
            'customer_view' => $this->customerView($header, $doc, $money),
            'label' => ['calculated' => 'Calculated by the server', 'confidence' => $doc['confidence'], 'rounding' => $doc['rounding_level']],
        ]);
    }

    /**
     * The document as the customer would read it, built from the same figures the server just worked out (never from anything
     * the browser added up). Plain strings only; the editor writes them in with textContent.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function customerView(array $header, array $doc, \Closure $money): array
    {
        $context = $this->ledger();
        $profile = app(SalesProfiles::class)->for($context);
        $party = Party::query()->find($header['party_id'] ?? 0);
        $ccy = $doc['currency'];
        $vat = (bool) $doc['vat_registered'];
        $title = (new SalesDocument(['kind' => $header['kind'] ?? 'tax_invoice']))->title($vat);

        $lines = [];
        foreach ($doc['lines'] as $l) {
            $qty = rtrim(rtrim(number_format($l['quantity_e4'] / 10000, 4, '.', ''), '0'), '.');
            $detail = $qty.($l['unit'] ? ' '.$l['unit'] : '').' at '.$money((int) $l['unit_cents'], $ccy);
            if ((int) $l['discount_bp'] > 0) {
                $detail .= ', less '.rtrim(rtrim(number_format($l['discount_bp'] / 100, 2, '.', ''), '0'), '.').'%';
            }
            $lines[] = [
                'description' => (string) $l['description'], 'detail' => $detail,
                'vat' => $vat && $l['tax_code'] ? TaxWords::label($l['tax_code'], (int) $l['rate_bp']) : '',
                'amount' => $money((int) ($vat ? $l['net_cents'] : $l['gross_cents']), $ccy),
            ];
        }

        $rows = [];
        if ($vat) {
            $rows[] = ['label' => 'Total before VAT', 'value' => $money((int) $doc['net_cents'], $ccy)];
            $rows[] = ['label' => 'VAT', 'value' => $money((int) $doc['tax_cents'], $ccy)];
        }
        $rows[] = ['label' => 'Total due', 'value' => $money((int) $doc['total_cents'], $ccy), 'total' => true];

        return [
            'title' => $title,
            'number' => 'Number given when issued',
            'from' => (string) ($profile->displayName() ?? ''),
            'vat_number' => $vat && $profile->vat_number ? 'VAT number '.$profile->vat_number : '',
            'to' => (string) ($party?->name ?? ''),
            'date' => ! empty($header['issue_date']) ? CarbonImmutable::parse((string) $header['issue_date'])->format('j M Y') : '',
            'due' => ! empty($header['due_date']) ? 'Due '.CarbonImmutable::parse((string) $header['due_date'])->format('j M Y') : '',
            'reference' => ! empty($header['reference']) ? 'Your reference '.$header['reference'] : '',
            'lines' => $lines, 'totals' => $rows,
            'notes' => (string) ($header['notes'] ?? ''), 'terms' => (string) ($header['terms'] ?? ''),
        ];
    }
}
