<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\ReceivablesSource;
use App\Contracts\Intelligence\TaxDeadlineSource;
use App\Enums\Capability;
use App\Models\Intelligence\FinanceInsight;
use App\Services\Controls\ApprovalService;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The scheduled "tell someone" checks, per ledger. Each is idempotent: the dedupe key names the thing (and, for
 * deadlines, how close it is), so running the job twice a day, or again after a failure, sends nothing twice.
 *
 *  approval_waiting  a request is pending and the person can decide it;
 *  tax_deadline      a deadline from the tax calendar is 14, 7, 3, 1 or 0 days away (needs the Tax module's calendar);
 *  overdue_summary   weekly, when the receivables source knows of overdue invoices (needs Sales' invoices);
 *  insight           a warning or critical finding appeared.
 */
final class NotificationRules
{
    private const DEADLINE_STEPS = [0, 1, 3, 7, 14];

    public function __construct(
        private readonly FinanceNotifier $notifier,
        private readonly ApprovalService $approvals,
        private readonly ReceivablesSource $receivables,
        private readonly TaxDeadlineSource $deadlines,
    ) {}

    /** @return array<string, int> notifications created per rule */
    public function run(LedgerContext $context, ?CarbonImmutable $asOf = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();

        return LedgerContext::run($context, fn () => [
            'approval_waiting' => $this->approvalsWaiting($context),
            'tax_deadline' => $this->taxDeadlines($context, $asOf),
            'overdue_summary' => $this->overdueSummary($context, $asOf),
            'insight' => $this->insights($context),
        ]);
    }

    private function approvalsWaiting(LedgerContext $context): int
    {
        $sent = 0;
        $afterHours = (int) config('intelligence.notifications.approval_waiting_after_hours', 1);

        foreach ($this->notifier->recipientsWith($context, Capability::ViewFinance) as $user) {
            foreach ($this->approvals->waitingFor($user, $context) as $waiting) {
                $request = $waiting['request'];

                $asked = $request->requested_at ?? $request->created_at;
                if ($asked !== null && $asked->gt(now()->subHours($afterHours))) {
                    continue; // fresh: the requester has probably just told them
                }

                $label = strtolower($request->subject_kind->label());
                $note = $this->notifier->send(
                    $context, $user, 'approval_waiting',
                    "A {$label} for ".Money::format((int) $request->amount_cents, $request->currency).' is waiting for your approval',
                    ($request->requester?->name ? $request->requester->name.' asked' : 'It was requested')." for approval of a {$label}. ".($waiting['blocked'] ? 'Note: '.$waiting['blocked'] : ''),
                    fx_route(['controls.approvals.index', 'approvals.index']),
                    'approval:'.$request->id,
                    'notice',
                );
                $sent += $note ? 1 : 0;
            }
        }

        return $sent;
    }

    private function taxDeadlines(LedgerContext $context, CarbonImmutable $asOf): int
    {
        $sent = 0;
        $upcoming = $this->deadlines->dueBetween($context, $asOf, $asOf->addDays(max(self::DEADLINE_STEPS)));

        if ($upcoming === []) {
            return 0;
        }

        $people = $this->notifier->recipientsWith($context, Capability::PrepareTaxReturn);

        foreach ($upcoming as $deadline) {
            $left = (int) $asOf->diffInDays(CarbonImmutable::parse($deadline['due_on'])->startOfDay(), false);
            if ($left < 0) {
                continue;
            }

            $step = collect(self::DEADLINE_STEPS)->first(fn (int $s) => $left <= $s);
            foreach ($people as $user) {
                $note = $this->notifier->send(
                    $context, $user, 'tax_deadline',
                    $deadline['label'].($left === 0 ? ' is due today' : ' is due in '.($left === 1 ? '1 day' : "{$left} days")),
                    "{$deadline['label']} is due on {$deadline['due_on']}. Check the date with your tax practitioner or SARS before you rely on it.",
                    $deadline['href'],
                    'tax:'.$deadline['key'].':'.$deadline['due_on'].':'.$step,
                    $left <= 3 ? 'warning' : 'notice',
                );
                $sent += $note ? 1 : 0;
            }
        }

        return $sent;
    }

    private function overdueSummary(LedgerContext $context, CarbonImmutable $asOf): int
    {
        $overdue = $this->receivables->overdueInvoices($context, $asOf);

        if ($overdue === null || $overdue === []) {
            return 0;
        }

        $total = array_sum(array_column($overdue, 'open_cents'));
        $currency = $overdue[0]['currency'];
        $week = $asOf->format('o-\WW');
        $sent = 0;

        foreach ($this->notifier->recipientsWith($context, Capability::IssueInvoice) as $user) {
            $note = $this->notifier->send(
                $context, $user, 'overdue_summary',
                count($overdue).' overdue '.(count($overdue) === 1 ? 'invoice' : 'invoices').', '.Money::format($total, $currency),
                'This week '.count($overdue).' '.(count($overdue) === 1 ? 'invoice is' : 'invoices are').' past due, '.Money::format($total, $currency).' in all.',
                fx_route(['sales.invoices.index', 'invoices.index']),
                'overdue:'.$week,
                'warning',
            );
            $sent += $note ? 1 : 0;
        }

        return $sent;
    }

    private function insights(LedgerContext $context): int
    {
        $sent = 0;
        $findings = FinanceInsight::query()->forContext($context)->visible()->whereIn('severity', ['warning', 'critical'])->get();

        if ($findings->isEmpty()) {
            return 0;
        }

        foreach ($this->notifier->recipientsWith($context, Capability::ViewAudit) as $user) {
            foreach ($findings as $finding) {
                $note = $this->notifier->send($context, $user, 'insight', $finding->title, $finding->body, fx_route('intel.attention.index'), 'insight:'.$finding->id, $finding->severity);
                $sent += $note ? 1 : 0;
            }
        }

        return $sent;
    }
}
