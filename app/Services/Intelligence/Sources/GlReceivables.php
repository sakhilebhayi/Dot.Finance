<?php

namespace App\Services\Intelligence\Sources;

use App\Contracts\Intelligence\ReceivablesSource;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Models\Shared\Party;
use App\Services\Accounting\GlQuery;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Receivables straight from the general ledger: the net debit on the receivables account, per customer. It knows
 * nothing about invoices or due dates, so it cannot say what is overdue (null), and it has no data at all until
 * accounting is on and something has been posted to receivables.
 */
final class GlReceivables implements ReceivablesSource
{
    public function __construct(private readonly GlQuery $gl) {}

    public function owedByCustomers(LedgerContext $context): ?array
    {
        $settings = LedgerSetting::query()->forContext($context)->first();
        if ($settings === null || ! $settings->isEnabled()) {
            return null;
        }

        $ar = GlAccount::query()->forContext($context)->where('system_key', 'ar')->first();
        if ($ar === null) {
            return null;
        }

        $rows = $this->gl->ownedLines($context)
            ->where('l.gl_account_id', $ar->id)
            ->groupBy('l.party_id')
            ->selectRaw('l.party_id, SUM(l.debit_cents) - SUM(l.credit_cents) AS net, MIN(l.entry_date) AS oldest')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $names = Party::query()->forContext($context)->withTrashed()->whereIn('id', $rows->pluck('party_id')->filter()->all())->pluck('name', 'id');

        $out = [];
        foreach ($rows as $row) {
            $net = (int) $row->net;
            if ($net <= 0) {
                continue;
            }

            $out[] = [
                'party' => $row->party_id === null ? 'Not assigned to a customer' : (string) ($names[$row->party_id] ?? 'Unknown customer'),
                'owed_cents' => $net,
                'currency' => (string) $settings->base_currency,
                'oldest' => $row->oldest !== null ? substr((string) $row->oldest, 0, 10) : null,
                'href' => $row->party_id !== null && fx_route_exists('parties.show') ? route('parties.show', $row->party_id) : null,
            ];
        }

        usort($out, fn ($a, $b) => $b['owed_cents'] <=> $a['owed_cents']);

        return $out;
    }

    public function overdueInvoices(LedgerContext $context, CarbonImmutable $asOf): ?array
    {
        // Due dates live on invoices, which the general ledger does not hold.
        return null;
    }
}
