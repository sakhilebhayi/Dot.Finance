<?php

namespace App\Services\Tax;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\User;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Tax\Dto\IncomeTaxEstimate;
use App\Services\Tax\Exceptions\TaxException;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A SUGGESTED journal for the income tax provision (tax expense against income tax payable). It is a draft: nothing is posted
 * until a person picks the expense account and confirms. The amount is the year-to-date ESTIMATE less what earlier provisions
 * for the same tax year already booked, so confirming twice never doubles the provision.
 */
final class IncomeTaxProvision
{
    public function __construct(private readonly JournalPoster $poster, private readonly GlSync $sync) {}

    /**
     * @return array{amount_cents: int, already_provided_cents: int, estimate_cents: int, lines: list<array{account: string, debit_cents: int, credit_cents: int, label: string}>, basis: string}
     */
    public function suggest(LedgerContext $ctx, IncomeTaxEstimate $estimate, ?GlAccount $expenseAccount = null): array
    {
        $provided = $this->provided($ctx, $estimate->taxYearEnd);
        $delta = $estimate->taxCents - $provided;
        $lines = [];

        if ($delta !== 0) {
            $expense = $expenseAccount?->label() ?? 'Income tax expense (choose the account)';
            $lines[] = ['account' => $expense, 'debit_cents' => max($delta, 0), 'credit_cents' => max(-$delta, 0), 'label' => 'Income tax expense for the year ending '.$estimate->taxYearEnd];
            $lines[] = ['account' => 'Income tax payable', 'debit_cents' => max(-$delta, 0), 'credit_cents' => max($delta, 0), 'label' => 'Income tax owed to SARS (estimate)'];
        }

        return ['amount_cents' => $delta, 'already_provided_cents' => $provided, 'estimate_cents' => $estimate->taxCents, 'lines' => $lines, 'basis' => TaxBasis::SUGGESTION];
    }

    public function post(LedgerContext $ctx, IncomeTaxEstimate $estimate, GlAccount $expenseAccount, User $by, ?CarbonImmutable $on = null): JournalEntry
    {
        if (! $this->sync->isEnabled($ctx)) {
            throw new TaxException('Accounting is not switched on, so there is nowhere to post a provision.');
        }
        if ($expenseAccount->class !== 'expense' || ! $expenseAccount->is_postable || ! $expenseAccount->is_active) {
            throw new TaxException('Choose an active expense account for the income tax expense.');
        }

        return LedgerContext::run($ctx, fn () => DB::transaction(function () use ($ctx, $estimate, $expenseAccount, $by, $on) {
            $suggestion = $this->suggest($ctx, $estimate, $expenseAccount);
            if ($suggestion['amount_cents'] === 0) {
                throw new TaxException('The provision already matches the estimate: nothing to post.');
            }

            $delta = $suggestion['amount_cents'];
            $n = $this->count($ctx, $estimate->taxYearEnd) + 1;
            $date = $on ?? min(CarbonImmutable::today(), CarbonImmutable::parse($estimate->taxYearEnd));

            $entry = $this->poster->post(new PostingRequest(
                $date, 'adjustment', 'income_tax_provision:'.$estimate->taxYearEnd.':'.$n, 1,
                'Income tax provision (estimate) for the year ending '.$estimate->taxYearEnd,
                [
                    new PostingLine($expenseAccount->id, debitCents: max($delta, 0), creditCents: max(-$delta, 0), description: 'Income tax expense (estimate)'),
                    new PostingLine('income_tax_payable', debitCents: max(-$delta, 0), creditCents: max($delta, 0), description: 'Income tax payable (estimate)'),
                ],
                $by->id,
            ));

            FinanceAudit::record($ctx->ownerAttributes(), 'provision_posted', 'tax_estimate', null, 'Income tax provision '.$estimate->taxYearEnd, ['amount_cents' => ['after' => $delta]], 'confirmed by a person; an estimate', null, $by->id);

            return $entry;
        }));
    }

    private function provided(LedgerContext $ctx, string $yearEnd): int
    {
        return (int) JournalLine::query()->forContext($ctx)
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->join('gl_accounts as g', 'g.id', '=', 'journal_lines.gl_account_id')
            ->where('e.source_type', 'adjustment')->where('e.source_id', 'like', 'income_tax_provision:'.$yearEnd.':%')
            ->where('g.system_key', 'income_tax_payable')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_cents), 0) - COALESCE(SUM(journal_lines.debit_cents), 0) AS net')->value('net');
    }

    private function count(LedgerContext $ctx, string $yearEnd): int
    {
        return JournalEntry::query()->forContext($ctx)->where('source_type', 'adjustment')->where('source_id', 'like', 'income_tax_provision:'.$yearEnd.':%')->count();
    }
}
