<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRecurringTemplate;
use App\Models\User;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Invoices made on a schedule from a template. For each template and period exactly one invoice is made, however
 * many times the command runs, on however many servers: the document is keyed (template, period) in the database,
 * and the draft is created through a client token derived from the same pair. A template in "issue" mode issues the
 * invoice at once; if issuing is refused (a closed period, a missing VAT number, an approval policy) the invoice is
 * left as a draft and the reason is on the template's run log, never lost and never retried into a duplicate.
 */
final class RecurringInvoices
{
    public function __construct(private readonly DocumentDrafts $drafts, private readonly DocumentIssuer $issuer) {}

    /** Every ledger with an active template. @return array<string, array<string, int>> */
    public function runAll(?CarbonImmutable $today = null): array
    {
        $out = [];
        foreach (LedgerContext::owningRowsIn('sales_recurring_templates') as $context) {
            $out[$context->key()] = $this->runFor($context, $today);
        }

        return $out;
    }

    /** @return array<string, int> */
    public function runFor(LedgerContext $context, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $counts = ['templates' => 0, 'created' => 0, 'issued' => 0, 'left_as_draft' => 0, 'existing' => 0];

        LedgerContext::run($context, function () use ($context, $today, &$counts) {
            $ids = SalesRecurringTemplate::query()->forContext($context)->where('status', 'active')->where('next_run_date', '<=', $today->toDateString())->orderBy('id')->pluck('id');

            foreach ($ids as $id) {
                $counts['templates']++;
                // a template that was down for a while catches up one period at a time, never more than a dozen in one run
                for ($i = 0; $i < 12; $i++) {
                    $result = DB::transaction(fn () => $this->runOnce($context, (int) $id, $today));
                    if ($result === null) {
                        break;
                    }
                    $counts[$result]++;
                }
            }
        });

        return $counts;
    }

    /** @return ?string created|issued|left_as_draft|existing, or null when the template has nothing more due */
    private function runOnce(LedgerContext $context, int $id, CarbonImmutable $today): ?string
    {
        $template = SalesRecurringTemplate::query()->forContext($context)->whereKey($id)->lockForUpdate()->first();
        if ($template === null || $template->status !== 'active' || $template->next_run_date->toDateString() > $today->toDateString()) {
            return null;
        }

        $period = CarbonImmutable::parse($template->next_run_date->toDateString());
        if ($template->end_date !== null && $period->gt(CarbonImmutable::parse($template->end_date->toDateString()))) {
            $template->forceFill(['status' => 'ended'])->save();

            return null;
        }

        $existing = SalesDocument::query()->forContext($context)->where('recurring_template_id', $template->id)->where('recurring_period', $period->toDateString())->first();
        $result = 'existing';

        if ($existing === null) {
            $creator = User::find($template->created_by) ?? throw new SalesViolation('The person who set this template up no longer exists.', 'invalid');
            $draft = $this->drafts->create($context, $creator, [
                'kind' => 'tax_invoice', 'party_id' => $template->party_id, 'currency' => $template->currency, 'issue_date' => $period->toDateString(),
                'due_date' => $period->addDays((int) $template->terms_days)->toDateString(), 'prices_include_tax' => $template->prices_include_tax,
                'reference' => $template->reference, 'notes' => $template->notes, 'client_token' => 'rec-'.$template->id.'-'.$period->format('Ymd'),
            ], $template->lines);
            $draft->forceFill(['recurring_template_id' => $template->id, 'recurring_period' => $period->toDateString()])->save();
            $result = 'created';

            if ($template->mode === 'issue') {
                try {
                    $out = $this->issuer->issue($draft, $creator);
                    $result = $out['awaiting_approval'] ? 'left_as_draft' : 'issued';
                } catch (SalesViolation $e) {
                    $draft->forceFill(['approval_note' => mb_substr('Not issued automatically: '.$e->getMessage(), 0, 255)])->save();
                    $result = 'left_as_draft';
                }
            }
        }

        $template->forceFill(['last_run_for' => $period->toDateString(), 'next_run_date' => $this->next($template, $period)->toDateString()])->save();

        return $result;
    }

    /** The occurrence after $from. Months and years count from the start date, so the 31st does not drift to the 28th for good. */
    public function next(SalesRecurringTemplate $template, CarbonImmutable $from): CarbonImmutable
    {
        $start = CarbonImmutable::parse($template->start_date->toDateString());
        $every = max(1, (int) $template->every);

        for ($k = 1; $k < 5000; $k++) {
            $candidate = match ($template->frequency) {
                'weekly' => $start->addWeeks($k * $every),
                'monthly' => $start->addMonthsNoOverflow($k * $every),
                'quarterly' => $start->addMonthsNoOverflow($k * $every * 3),
                'yearly' => $start->addYearsNoOverflow($k * $every),
                default => throw new SalesViolation('Unknown frequency.', 'invalid'),
            };
            if ($candidate->gt($from)) {
                return $candidate;
            }
        }

        throw new SalesViolation('The schedule never advances.', 'invalid');
    }
}
