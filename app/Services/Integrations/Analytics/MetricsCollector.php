<?php

namespace App\Services\Integrations\Analytics;

use App\Models\Account;
use App\Models\Controls\ApprovalRequest;
use App\Models\Intelligence\FinanceInsight;
use App\Models\Transaction;
use App\Services\Accounting\GlSync;
use App\Services\Intelligence\Sources\GlReceivables;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * The numbers one company ledger publishes: AGGREGATE counts and totals only. Never a transaction, a description,
 * a party name, an account name, an amount per person or anything that identifies an individual entry. Every value
 * is a non-negative whole number (Dot.Analytics' "count" and "cents" kinds); a figure that could be negative is not
 * published. Money is in ONE currency, named by the `currency` key: the books' base currency, else the currency
 * holding the most accounts.
 *
 * Keys (the contract proposed to Dot.Analytics in docs/integrations/analytics-finance-metrics.md):
 *   income_mtd_cents, expense_mtd_cents, transactions_mtd, accounts, open_findings, pending_approvals,
 *   receivables_cents (only when accounting is on and customers owe something).
 */
final class MetricsCollector
{
    public function __construct(private readonly GlSync $gl, private readonly GlReceivables $receivables) {}

    /** @return array<string, int|string> */
    public function collect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        return LedgerContext::run($context, function () use ($context, $asOf) {
            $settings = $this->gl->settings($context);
            $currency = $settings?->isEnabled() ? (string) $settings->base_currency : $this->mainCurrency($context);

            $metrics = [
                'accounts' => Account::query()->forContext($context)->count(),
                'open_findings' => FinanceInsight::query()->forContext($context)->visible()->count(),
                'pending_approvals' => ApprovalRequest::query()->forContext($context)->where('status', ApprovalRequest::PENDING)->count(),
            ];

            if ($currency !== null) {
                $from = $asOf->startOfMonth()->toDateString();
                $to = $asOf->addDay()->toDateString();

                $rows = Transaction::query()->forContext($context)->counted()
                    ->whereIn('type', ['income', 'expense'])->where('currency', $currency)
                    ->where('date', '>=', $from)->where('date', '<', $to)
                    ->selectRaw('type, SUM(amount_cents) AS total, COUNT(*) AS n')->groupBy('type')->get()->keyBy('type');

                $metrics['income_mtd_cents'] = (int) ($rows['income']->total ?? 0);
                $metrics['expense_mtd_cents'] = (int) ($rows['expense']->total ?? 0);
                $metrics['transactions_mtd'] = (int) (($rows['income']->n ?? 0) + ($rows['expense']->n ?? 0));

                $owed = $this->receivables->owedByCustomers($context);
                if ($owed !== null && $owed !== []) {
                    $metrics['receivables_cents'] = (int) array_sum(array_column($owed, 'owed_cents'));
                }

                $metrics['currency'] = $currency;
            }

            return $metrics;
        });
    }

    private function mainCurrency(LedgerContext $context): ?string
    {
        $row = Account::query()->forContext($context)->selectRaw('currency, COUNT(*) AS n')->groupBy('currency')->orderByDesc('n')->orderBy('currency')->first();

        return $row?->currency;
    }
}
