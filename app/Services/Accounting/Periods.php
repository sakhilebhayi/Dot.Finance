<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Exceptions\AccountingNotReady;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accounting periods are the calendar months of the fiscal year, created the first time something is
 * posted into them. Closing a month locks the books through its last day (ledger_settings.
 * books_closed_through) and, for the last month of the fiscal year, posts the closing entry that moves
 * the year's income and expense into retained earnings. Months close in order and reopen last-in first-out,
 * so the lock is always one contiguous date.
 */
class Periods
{
    /** The period containing $date, created if it does not exist. Call it with the settings row locked. */
    public function forDate(LedgerContext $context, CarbonImmutable $date, LedgerSetting $settings): AccountingPeriod
    {
        $start = $date->startOfMonth();

        $existing = AccountingPeriod::query()->forContext($context)->where('start_date', $start->toDateString())->first();
        if ($existing !== null) {
            return $existing;
        }

        $startMonth = max(1, (int) $settings->fiscal_year_start_month);
        $yearEndMonth = $startMonth === 1 ? 12 : $startMonth - 1;
        $fiscalYear = $startMonth === 1 || $date->month < $startMonth ? $date->year : $date->year + 1;

        return AccountingPeriod::create($context->ownerAttributes() + [
            'start_date' => $start->toDateString(),
            'end_date' => $date->endOfMonth()->toDateString(),
            'fiscal_year' => $fiscalYear,
            'is_year_end' => $date->month === $yearEndMonth,
            'status' => 'open',
        ]);
    }

    /** First day of the fiscal year that contains $date. */
    public function fiscalYearStart(CarbonImmutable $date, int $startMonth): CarbonImmutable
    {
        $year = $date->month >= $startMonth ? $date->year : $date->year - 1;

        return CarbonImmutable::create($year, $startMonth, 1)->startOfDay();
    }

    /**
     * Close a period. Every earlier period that exists must be closed already. For a year-end period the
     * closing entry is posted first (dated the period's last day, while the period is still open).
     */
    public function close(LedgerContext $context, AccountingPeriod $period, ?User $by = null): AccountingPeriod
    {
        return DB::transaction(function () use ($context, $period, $by) {
            $settings = $this->settings($context);
            $period = AccountingPeriod::query()->forContext($context)->lockForUpdate()->findOrFail($period->id);

            if ($period->isClosed()) {
                throw new AccountingException("{$period->label()} is already closed.");
            }

            $blocking = AccountingPeriod::query()->forContext($context)->where('start_date', '<', $period->start_date->toDateString())->where('status', 'open')->orderBy('start_date')->first();
            if ($blocking !== null) {
                throw new AccountingException("Close {$blocking->label()} first: periods close in order.");
            }

            $closing = null;
            if ($period->is_year_end) {
                $closing = $this->postClosingEntry($context, $period, $settings, $by);
            }

            $period->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by_user_id' => $by?->id,
                'closing_entry_id' => $closing?->id,
            ]);

            $settings->forceFill(['books_closed_through' => $period->end_date->toDateString()])->save();

            FinanceAudit::record($context->ownerAttributes(), 'period_closed', 'period', $period->id, $period->label(), [
                'books_closed_through' => ['after' => $period->end_date->toDateString()],
                'closing_entry_id' => ['after' => $closing?->id],
            ]);

            return $period->refresh();
        });
    }

    /**
     * Close every month up to and including the one ending $end, oldest first, creating the monthly periods that
     * have no activity yet. Stops with the reason if a month cannot be closed. Returns how many were closed.
     */
    public function closeThrough(LedgerContext $context, CarbonImmutable $end, ?User $by = null): int
    {
        return DB::transaction(function () use ($context, $end, $by) {
            $settings = $this->settings($context);
            $first = AccountingPeriod::query()->forContext($context)->orderBy('start_date')->first();
            $start = $first?->start_date !== null
                ? CarbonImmutable::parse($first->start_date->toDateString())
                : ($settings->accounting_start_date !== null ? CarbonImmutable::parse($settings->accounting_start_date->toDateString())->startOfMonth() : null);

            if ($start === null) {
                throw new AccountingException('There is nothing to close yet: no entry has been posted.');
            }

            // Make sure every month in between has a row, so they can be closed in order.
            for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addMonthNoOverflow()->startOfMonth()) {
                $this->forDate($context, $cursor, $settings);
            }

            $closed = 0;
            $open = AccountingPeriod::query()->forContext($context)->where('status', 'open')->where('end_date', '<=', $end->toDateString())->orderBy('start_date')->get();
            foreach ($open as $period) {
                $this->close($context, $period, $by);
                $closed++;
            }

            return $closed;
        });
    }

    /** Reopen the most recently closed period (and only that one), with a reason. Audited. */
    public function reopen(LedgerContext $context, AccountingPeriod $period, string $reason, ?User $by = null): AccountingPeriod
    {
        if (trim($reason) === '') {
            throw new AccountingException('Say why the period is being reopened.');
        }

        return DB::transaction(function () use ($context, $period, $reason, $by) {
            $settings = $this->settings($context);
            $period = AccountingPeriod::query()->forContext($context)->lockForUpdate()->findOrFail($period->id);

            if (! $period->isClosed()) {
                throw new AccountingException("{$period->label()} is not closed.");
            }

            $later = AccountingPeriod::query()->forContext($context)->where('start_date', '>', $period->start_date->toDateString())->where('status', 'closed')->first();
            if ($later !== null) {
                throw new AccountingException("Reopen {$later->label()} first: periods reopen newest first.");
            }

            $previousClosed = AccountingPeriod::query()->forContext($context)
                ->where('start_date', '<', $period->start_date->toDateString())->where('status', 'closed')
                ->orderByDesc('start_date')->first();

            $before = $settings->books_closed_through?->toDateString();
            $settings->forceFill(['books_closed_through' => $previousClosed?->end_date->toDateString()])->save();

            // The year-end closing entry is undone: its effect lived only while the year was closed.
            if ($period->closing_entry_id !== null) {
                $entry = JournalEntry::query()->forContext($context)->find($period->closing_entry_id);
                if ($entry !== null && ! $entry->isReversed()) {
                    app(JournalPoster::class)->reverse($entry, 'Period reopened: '.$reason);
                }
            }

            $period->update([
                'status' => 'open',
                'reopened_at' => now(),
                'reopened_by_user_id' => $by?->id,
                'reopen_reason' => mb_substr($reason, 0, 255),
                'closing_entry_id' => null,
            ]);

            FinanceAudit::record($context->ownerAttributes(), 'period_reopened', 'period', $period->id, $period->label(), [
                'books_closed_through' => ['before' => $before, 'after' => $previousClosed?->end_date->toDateString()],
                'reason' => ['after' => mb_substr($reason, 0, 255)],
            ], 'reason: '.mb_substr($reason, 0, 200));

            return $period->refresh();
        });
    }

    /**
     * Year-end: every income and expense account with a balance for the fiscal year goes to zero against
     * retained earnings. Returns null when the year had no income or expense activity.
     */
    private function postClosingEntry(LedgerContext $context, AccountingPeriod $period, LedgerSetting $settings, ?User $by): ?JournalEntry
    {
        $yearStart = $this->fiscalYearStart($period->end_date->toImmutable(), max(1, (int) $settings->fiscal_year_start_month));

        $rows = DB::table('journal_lines as l')
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('g.class', ['income', 'expense'])
            ->where('l.entry_date', '>=', $yearStart->toDateString())
            ->where('l.entry_date', '<=', $period->end_date->toDateString())
            ->where(fn ($q) => $context->isCompany() ? $q->where('l.team_id', $context->teamId) : $q->where('l.user_id', $context->userId))
            ->groupBy('l.gl_account_id')
            ->selectRaw('l.gl_account_id, SUM(l.debit_cents) AS debits, SUM(l.credit_cents) AS credits')
            ->orderBy('l.gl_account_id')
            ->get();

        $lines = [];
        $net = 0; // positive = net debit moved out of P&L (a loss)
        foreach ($rows as $row) {
            $balance = (int) $row->debits - (int) $row->credits;
            if ($balance === 0) {
                continue;
            }

            $lines[] = $balance > 0
                ? new PostingLine((int) $row->gl_account_id, creditCents: $balance, description: 'Year-end close')
                : new PostingLine((int) $row->gl_account_id, debitCents: -$balance, description: 'Year-end close');
            $net += $balance;
        }

        if ($lines === []) {
            return null;
        }

        // Net P&L (credit-heavy = profit) lands in retained earnings; income that exactly offsets
        // expense still clears the P&L accounts but leaves retained earnings alone.
        if ($net !== 0) {
            $lines[] = $net > 0
                ? new PostingLine('retained_earnings', debitCents: $net, description: 'Loss for the year')
                : new PostingLine('retained_earnings', creditCents: -$net, description: 'Profit for the year');
        }

        if (count($lines) < 2) {
            return null;
        }

        return app(JournalPoster::class)->post(new PostingRequest(
            CarbonImmutable::parse($period->end_date->toDateString()),
            'period_close', $period->id, 1,
            'Year-end close: fiscal year '.$period->fiscal_year,
            $lines, $by?->id,
        ));
    }

    private function settings(LedgerContext $context): LedgerSetting
    {
        return LedgerSetting::query()->forContext($context)->lockForUpdate()->first()
            ?? throw new AccountingNotReady('This ledger has no accounting settings yet.');
    }

    /** The open period containing today (created on demand), for default displays. */
    public function current(LedgerContext $context): ?AccountingPeriod
    {
        return AccountingPeriod::query()->forContext($context)->orderByDesc('start_date')->first();
    }
}
