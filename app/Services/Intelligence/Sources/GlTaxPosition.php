<?php

namespace App\Services\Intelligence\Sources;

use App\Contracts\Intelligence\TaxPositionSource;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Services\Accounting\GlQuery;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * VAT from the general ledger's VAT accounts over a date range: output VAT (collected) minus input VAT
 * (claimable). It is a working figure, not a return: the Tax module's VAT201 preparation is the filing document
 * and, when it is present, its binding replaces this one. It knows no deadlines.
 */
final class GlTaxPosition implements TaxPositionSource
{
    public function __construct(private readonly GlQuery $gl) {}

    public function vatPosition(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $settings = LedgerSetting::query()->forContext($context)->first();
        if ($settings === null || ! $settings->isEnabled()) {
            return null;
        }

        $accounts = GlAccount::query()->forContext($context)->whereIn('system_key', ['vat_output', 'vat_input'])->pluck('id', 'system_key');
        if ($accounts->isEmpty()) {
            return null;
        }

        $rows = $this->gl->ownedLines($context)
            ->whereIn('l.gl_account_id', $accounts->values()->all())
            ->where('l.entry_date', '>=', $from->toDateString())
            ->where('l.entry_date', '<=', $to->toDateString())
            ->groupBy('l.gl_account_id')
            ->selectRaw('l.gl_account_id, SUM(l.debit_cents) AS debits, SUM(l.credit_cents) AS credits')
            ->get()
            ->keyBy('gl_account_id');

        if ($rows->isEmpty()) {
            return null;
        }

        $out = $rows->get($accounts['vat_output'] ?? 0);
        $in = $rows->get($accounts['vat_input'] ?? 0);
        $output = $out ? (int) $out->credits - (int) $out->debits : 0;
        $input = $in ? (int) $in->debits - (int) $in->credits : 0;

        return [
            'output_cents' => $output,
            'input_cents' => $input,
            'net_cents' => $output - $input,
            'currency' => (string) $settings->base_currency,
            'href' => fx_route('accounting.general-ledger'),
            'basis_note' => 'Worked out from the VAT accounts of the general ledger. It is not a VAT201: the return is prepared on the Tax screens.',
        ];
    }

    public function deadlines(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        return null;
    }
}
