<?php

namespace App\Services\Purchases;

use App\Contracts\Reporting\OpenItem;
use App\Models\Accounting\LedgerSetting;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Shared\PaymentAllocation;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Payables open items for ageing and the cash projection: one per unpaid bill or expense claim with its number and due date,
 * and supplier credits and money paid on account as negative items, all in the ledger's base currency (each document at what the
 * books carry it at), as the payable account is kept.
 */
final class PurchaseOpenItems
{
    public function __construct(private readonly ApLedger $ledger) {}

    /** @return list<OpenItem> */
    public function items(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $currency = (string) (LedgerSetting::query()->forContext($context)->value('base_currency') ?? 'ZAR');
        $out = [];

        foreach ($this->ledger->openItems($context, $asOf) as $item) {
            if ($item['open_base'] === 0) {
                continue;
            }
            $due = $item['due_date'] ?? $item['date'];
            $href = $item['route'] !== null ? fx_route($item['route'], [$item['id']]) : null;
            $out[] = new OpenItem($item['party_id'] !== null ? (int) $item['party_id'] : null, (string) $item['party'], (string) $item['number'], (string) $item['date'], (string) $due, (int) $item['open_base'], $currency, $href);
        }

        return $out;
    }

    /** @return array<int, array{settled: int, avg_days_late: int}> */
    public function history(LedgerContext $context): array
    {
        $bills = LedgerContext::run($context, fn () => Bill::query()->where('kind', 'bill')->where('status', 'paid')->whereNotNull('due_date')->get(['id', 'party_id', 'due_date']));
        $claims = LedgerContext::run($context, fn () => ExpenseClaim::query()->where('status', 'paid')->get(['id', 'party_id', 'claim_date']));
        $by = [];

        foreach ([['bill', $bills, 'due_date'], ['expense_claim', $claims, 'claim_date']] as [$type, $docs, $dueColumn]) {
            if ($docs->isEmpty()) {
                continue;
            }

            $last = PaymentAllocation::query()->forContext($context)->where('allocatable_type', $type)->whereIn('allocatable_id', $docs->pluck('id'))->whereNull('released_at')
                ->join('payments as p', 'p.id', '=', 'payment_allocations.payment_id')->where('p.status', 'recorded')
                ->selectRaw('payment_allocations.allocatable_id as document_id, MAX(p.paid_on) as paid_on')->groupBy('payment_allocations.allocatable_id')->pluck('paid_on', 'document_id');

            foreach ($docs as $doc) {
                if (! isset($last[$doc->id]) || $doc->party_id === null) {
                    continue;
                }
                $late = (int) CarbonImmutable::parse($doc->{$dueColumn}->toDateString())->diffInDays(CarbonImmutable::parse(substr((string) $last[$doc->id], 0, 10)), false);
                $by[(int) $doc->party_id]['n'] = ($by[(int) $doc->party_id]['n'] ?? 0) + 1;
                $by[(int) $doc->party_id]['days'] = ($by[(int) $doc->party_id]['days'] ?? 0) + $late;
            }
        }

        return array_map(fn (array $r) => ['settled' => $r['n'], 'avg_days_late' => intdiv($r['days'], $r['n'])], $by);
    }
}
