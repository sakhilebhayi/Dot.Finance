<?php

namespace App\Services\Tax;

use App\Models\Accounting\JournalEntry;
use App\Models\Tax\TaxPayrollRun;
use App\Models\User;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Tax\Exceptions\TaxException;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The payroll journal for a month's tax summary: a DRAFT is suggested, a person confirms, then it is posted.
 *
 * What it posts, and what it does not. Net pay is paid out of the bank and is recorded as a bank transaction in the cash
 * ledger (categorised to salaries). The journal adds the rest, so that Salaries expense ends up equal to gross pay plus
 * the employer's contributions:
 *
 *   Dr Salaries expense            PAYE + employee UIF      (the amounts withheld from employees)
 *   Dr Employer UIF expense        employer UIF
 *   Dr Employer SDL expense        SDL
 *     Cr PAYE payable              PAYE
 *     Cr UIF payable               employee UIF + employer UIF
 *     Cr SDL payable               SDL
 *   Dr PAYE payable / Cr Salaries expense   ETI claimed     (the incentive reduces what is owed and the employment cost)
 *
 * If the net pay is not recorded in the bank anywhere, salaries expense will be understated: the screen says so.
 */
final class PayrollJournal
{
    public function __construct(private readonly JournalPoster $poster, private readonly GlSync $sync) {}

    /** @return list<array{account: string, debit_cents: int, credit_cents: int, label: string}> */
    public function suggest(TaxPayrollRun $run): array
    {
        $lines = [
            ['account' => 'salaries_expense', 'debit_cents' => $run->paye_cents + $run->uif_employee_cents, 'credit_cents' => 0, 'label' => 'Tax and UIF withheld from employees'],
            ['account' => 'employer_uif_expense', 'debit_cents' => $run->uif_employer_cents, 'credit_cents' => 0, 'label' => 'Employer UIF contribution'],
            ['account' => 'employer_sdl_expense', 'debit_cents' => $run->sdl_cents, 'credit_cents' => 0, 'label' => 'Skills development levy'],
            ['account' => 'paye_payable', 'debit_cents' => 0, 'credit_cents' => $run->paye_cents, 'label' => 'PAYE owed to SARS'],
            ['account' => 'uif_payable', 'debit_cents' => 0, 'credit_cents' => $run->uif_employee_cents + $run->uif_employer_cents, 'label' => 'UIF owed (employee and employer)'],
            ['account' => 'sdl_payable', 'debit_cents' => 0, 'credit_cents' => $run->sdl_cents, 'label' => 'SDL owed to SARS'],
            ['account' => 'paye_payable', 'debit_cents' => $run->eti_claimed_cents, 'credit_cents' => 0, 'label' => 'Employment tax incentive claimed'],
            ['account' => 'salaries_expense', 'debit_cents' => 0, 'credit_cents' => $run->eti_claimed_cents, 'label' => 'Employment tax incentive reduces employment cost'],
        ];

        return array_values(array_filter($lines, fn ($l) => $l['debit_cents'] > 0 || $l['credit_cents'] > 0));
    }

    public function post(LedgerContext $ctx, TaxPayrollRun $run, User $by): JournalEntry
    {
        if (! $this->sync->isEnabled($ctx)) {
            throw new TaxException('Accounting is not switched on for this ledger, so there is nowhere to post the payroll journal.');
        }
        if ($run->isPosted()) {
            throw new TaxException('This payroll is already posted.');
        }
        $suggested = $this->suggest($run);
        if ($suggested === []) {
            throw new TaxException('Every amount is zero: there is nothing to post.');
        }

        return LedgerContext::run($ctx, fn () => DB::transaction(function () use ($ctx, $run, $by, $suggested) {
            $fresh = TaxPayrollRun::query()->forContext($ctx)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($fresh->isPosted()) {
                throw new TaxException('This payroll is already posted.');
            }

            $date = CarbonImmutable::parse($fresh->period_month->toDateString())->endOfMonth()->startOfDay();
            $entry = $this->poster->post(new PostingRequest(
                $date, 'payroll', (string) $fresh->id, 1, 'Payroll taxes for '.$date->format('F Y'),
                array_map(fn ($l) => new PostingLine($l['account'], debitCents: $l['debit_cents'], creditCents: $l['credit_cents'], description: $l['label']), $suggested),
                $by->id,
            ));

            // the model refuses to update a posted run; this is the single move from draft to posted
            TaxPayrollRun::query()->whereKey($fresh->id)->update(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'posted_by_user_id' => $by->id]);

            FinanceAudit::record($ctx->ownerAttributes(), 'posted', 'tax_payroll', (int) $fresh->id, 'Payroll journal '.$date->format('Y-m'), ['journal_entry_id' => ['after' => $entry->id]], null, null, $by->id);

            return $entry;
        }));
    }
}
