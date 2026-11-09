<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Accounting\GlBackfillRun;
use App\Models\Accounting\LedgerSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Exceptions\AccountingNotReady;
use App\Services\Accounting\Exceptions\UnpostableSource;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posts the history of the cash ledger into the journals, then proves it.
 *
 *   dryRun()  does everything in a transaction that is rolled back, and reports what apply would do and
 *             whether the books would tie out. Nothing is left behind.
 *   apply()   posts opening balances, then every counted transaction (transfers once per pair), in chunks,
 *             each chunk its own transaction, recording a cursor so an interrupted run resumes where it
 *             stopped. Replaying it changes nothing: posting is idempotent by source key.
 *   verify    runs the full verifier; only a clean report marks the run 'verified'.
 *   enable()  the cut-over: sets accounting_enabled_at so new writes post as they happen. Refused unless
 *             the latest apply verified.
 *
 * Rows that do not post (before the opening date, zero amount, legacy transfer rows, no exchange rate) are
 * listed as exceptions for the owner; nothing is fixed automatically.
 */
class GlBackfill
{
    public const CHUNK = 500;

    public function __construct(
        private readonly Chart $chart,
        private readonly GlSync $sync,
        private readonly GlVerifier $verifier,
        private readonly GlQuery $query,
        private readonly TrialBalance $trial,
    ) {}

    /** Prepare a ledger for accounting: settings row, chart from the template, a GL account for every cash account and category. Idempotent. */
    public function prepare(LedgerContext $context, string $baseCurrency, int $fiscalYearStartMonth, string $template): LedgerSetting
    {
        return DB::transaction(function () use ($context, $baseCurrency, $fiscalYearStartMonth, $template) {
            $settings = LedgerSetting::query()->forContext($context)->lockForUpdate()->first();

            if ($settings === null) {
                $settings = LedgerSetting::create($context->ownerAttributes() + [
                    'base_currency' => $baseCurrency,
                    'fiscal_year_start_month' => $fiscalYearStartMonth,
                    'chart_template' => $template,
                ]);
            } elseif (! $settings->isEnabled() && ! $this->hasJournals($context)) {
                // Still setting up: the choices can change until the first entry exists.
                $settings->update(['base_currency' => $baseCurrency, 'fiscal_year_start_month' => $fiscalYearStartMonth, 'chart_template' => $template]);
            }

            $this->chart->ensure($context, $settings->chart_template ?? $template);
            $this->chart->mapAll($context);
            $this->sync->flush();

            return $settings->refresh();
        });
    }

    public function hasJournals(LedgerContext $context): bool
    {
        return DB::table('journal_entries')->where(fn ($q) => $context->isCompany() ? $q->where('team_id', $context->teamId) : $q->where('user_id', $context->userId))->exists();
    }

    /** Computes everything apply would, then rolls it all back. The run record (outside the rollback) carries the report. */
    public function dryRun(LedgerContext $context, ?User $by = null): GlBackfillRun
    {
        $report = null;
        $exceptions = [];

        DB::beginTransaction();
        try {
            $this->sync->flush();
            [$exceptions, $counts] = LedgerContext::run($context, fn () => $this->postEverything($context, null));
            $report = $this->report($context, $counts);
        } finally {
            DB::rollBack();
            $this->sync->flush();
        }

        return $this->record($context, $by, 'dry_run', 'dry_run', $report, $exceptions, null);
    }

    /**
     * Post the history. Pass an interrupted run to resume it from its cursor.
     */
    public function apply(LedgerContext $context, ?User $by = null, ?GlBackfillRun $resume = null): GlBackfillRun
    {
        $this->settings($context);

        $run = $resume ?? GlBackfillRun::create($context->ownerAttributes() + [
            'mode' => 'apply', 'status' => 'running', 'started_by_user_id' => $by?->id, 'started_at' => now(),
        ]);

        $exceptions = [];
        $counts = ['entries' => 0, 'lines' => 0, 'sources' => 0];

        try {
            [$exceptions, $counts] = LedgerContext::run($context, fn () => $this->postEverything($context, $run));
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'report' => ['error' => $e->getMessage()]]);
            throw $e;
        }

        $report = $this->report($context, $counts);
        $verified = $report['verify']['ok'];

        $run->update([
            'status' => $verified ? 'verified' : 'applied', 'finished_at' => now(), 'cursor_phase' => 'done',
            'sources_total' => $counts['sources'], 'entries_posted' => $counts['entries'], 'lines_posted' => $counts['lines'],
            'exceptions' => $exceptions, 'report' => $report,
        ]);

        FinanceAudit::record($context->ownerAttributes(), 'gl_backfill', 'period', $run->id, 'GL backfill #'.$run->id, [
            'status' => ['after' => $run->status],
            'entries' => ['after' => $counts['entries']],
            'exceptions' => ['after' => count($exceptions)],
        ]);

        return $run->refresh();
    }

    /** The cut-over. Only after a verified run, and only once. */
    public function enable(LedgerContext $context): LedgerSetting
    {
        return DB::transaction(function () use ($context) {
            $settings = LedgerSetting::query()->forContext($context)->lockForUpdate()->first() ?? throw new AccountingNotReady('Prepare the ledger first.');

            if ($settings->isEnabled()) {
                return $settings;
            }

            $latest = GlBackfillRun::query()->forContext($context)->where('mode', 'apply')->orderByDesc('id')->first();
            if ($latest === null || $latest->status !== 'verified') {
                throw new AccountingException('Accounting is enabled only after an applied backfill has verified cleanly. Run the backfill and fix what it reports first.');
            }

            // Anything written between the verified run and now is picked up now, so the switch starts from a tied-out state.
            $report = $this->verifier->verify($context);
            if (! $report['ok']) {
                throw new AccountingException('The books no longer tie out; run the sweeper (finance:gl:post-pending) and verify again.');
            }

            $settings->forceFill([
                'accounting_enabled_at' => now(),
                'accounting_start_date' => $settings->accounting_start_date ?? $this->startDate($context),
            ])->save();

            FinanceAudit::record($context->ownerAttributes(), 'accounting_enabled', 'period', null, 'Accounting enabled', [
                'accounting_enabled_at' => ['after' => now()->toDateTimeString()],
            ]);

            $this->sync->flush();

            return $settings->refresh();
        });
    }

    /** @return array{0: list<array{source: string, reason: string}>, 1: array{entries: int, lines: int, sources: int}} */
    private function postEverything(LedgerContext $context, ?GlBackfillRun $run): array
    {
        $settings = $this->settings($context);

        if (! $this->chart->isComplete($context)) {
            $this->chart->ensure($context, $settings->chart_template ?? ChartTemplates::PERSONAL);
        }
        $this->chart->mapAll($context);
        $this->sync->flush();

        if ($settings->accounting_start_date === null) {
            $settings->forceFill(['accounting_start_date' => $this->startDate($context)])->save();
            $this->sync->flush();
        }

        $exceptions = [];
        $before = (int) DB::table('journal_entries')->where(fn ($q) => $context->isCompany() ? $q->where('team_id', $context->teamId) : $q->where('user_id', $context->userId))->count();
        $sources = 0;
        $phase = $run?->cursor_phase;
        $cursor = $run?->cursor_id ?? 0;

        // Phase 1: opening balances.
        if ($phase === null || $phase === 'opening') {
            Account::query()->forContext($context)->withTrashed()->where('id', '>', $cursor)->orderBy('id')->each(function (Account $account) use ($context, &$exceptions, &$sources, $run) {
                $sources++;
                $this->guard($exceptions, 'account #'.$account->id.' opening balance', fn () => DB::transaction(fn () => $this->sync->syncOpening($context, $account)));
                $run?->update(['cursor_phase' => 'opening', 'cursor_id' => $account->id]);
            });
            $phase = 'transactions';
            $cursor = 0;
            $run?->update(['cursor_phase' => 'transactions', 'cursor_id' => 0]);
        }

        // Phase 2: transactions, in id order, in chunks. A transfer is posted once for the pair.
        $seenGroups = [];
        Transaction::query()->forContext($context)->withTrashed()->where('id', '>', $cursor)->with('account')->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use ($context, &$exceptions, &$sources, &$seenGroups, $run) {
            DB::transaction(function () use ($chunk, $context, &$exceptions, &$sources, &$seenGroups) {
                foreach ($chunk as $t) {
                    $sources++;

                    $reason = PostingRules::skipReason($t, $t->account?->opening_balance_date !== null ? CarbonImmutable::parse($t->account->opening_balance_date->toDateString()) : null);
                    if ($reason !== null && ! $t->trashed()) {
                        $exceptions[] = ['source' => 'transaction #'.$t->id, 'reason' => $reason];
                    }

                    if ($t->type === 'transfer' && $t->transfer_group !== null) {
                        if (isset($seenGroups[$t->transfer_group])) {
                            continue;
                        }
                        $seenGroups[$t->transfer_group] = true;
                    }

                    $this->guard($exceptions, 'transaction #'.$t->id, fn () => $this->sync->syncTransaction($context, $t));
                }
            });

            $run?->update(['cursor_phase' => 'transactions', 'cursor_id' => $chunk->last()->id]);
        });

        $after = (int) DB::table('journal_entries')->where(fn ($q) => $context->isCompany() ? $q->where('team_id', $context->teamId) : $q->where('user_id', $context->userId))->count();
        $lines = (int) DB::table('journal_lines')->where(fn ($q) => $context->isCompany() ? $q->where('team_id', $context->teamId) : $q->where('user_id', $context->userId))->count();

        return [$exceptions, ['entries' => $after - $before, 'lines' => $lines, 'sources' => $sources]];
    }

    /** @param list<array{source: string, reason: string}> $exceptions */
    private function guard(array &$exceptions, string $label, callable $step): void
    {
        try {
            $step();
        } catch (UnpostableSource $e) {
            $exceptions[] = ['source' => $label, 'reason' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function report(LedgerContext $context, array $counts): array
    {
        $verify = $this->verifier->verify($context);
        $tb = $this->trial->asOf($context);

        return [
            'counts' => $counts,
            'verify' => $verify,
            'trial_balance' => ['total_debits' => $tb['total_debits'], 'total_credits' => $tb['total_credits'], 'balanced' => $tb['balanced'], 'equation_holds' => $tb['equation']['holds']],
            'cash_tie_out' => $this->query->cashTieOut($context),
        ];
    }

    private function record(LedgerContext $context, ?User $by, string $mode, string $status, ?array $report, array $exceptions, ?int $cursor): GlBackfillRun
    {
        return GlBackfillRun::create($context->ownerAttributes() + [
            'mode' => $mode, 'status' => $status, 'started_by_user_id' => $by?->id, 'started_at' => now(), 'finished_at' => now(),
            'sources_total' => $report['counts']['sources'] ?? 0, 'entries_posted' => $report['counts']['entries'] ?? 0, 'lines_posted' => $report['counts']['lines'] ?? 0,
            'exceptions' => $exceptions, 'report' => $report, 'cursor_id' => $cursor,
        ]);
    }

    private function startDate(LedgerContext $context): string
    {
        $accountDates = Account::query()->forContext($context)->whereNotNull('opening_balance_date')->min('opening_balance_date');
        $txnDate = Transaction::query()->forContext($context)->min('date');
        $dates = array_filter([$accountDates !== null ? substr((string) $accountDates, 0, 10) : null, $txnDate !== null ? substr((string) $txnDate, 0, 10) : null]);

        return $dates === [] ? now()->toDateString() : min($dates);
    }

    private function settings(LedgerContext $context): LedgerSetting
    {
        return LedgerSetting::query()->forContext($context)->first() ?? throw new AccountingNotReady('Prepare the ledger first (base currency, fiscal year, chart).');
    }
}
