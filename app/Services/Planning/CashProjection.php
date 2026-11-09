<?php

namespace App\Services\Planning;

use App\Contracts\Planning\PayrollCommitmentsProvider;
use App\Contracts\Planning\TaxObligationsProvider;
use App\Contracts\Reporting\OpenItemsProvider;
use App\Models\Accounting\LedgerSetting;
use App\Models\RecurringRule;
use App\Services\TransactionRecurrenceService;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * A projection of cash over the next 13 weeks or 12 months, built only from things that exist in the books or that a person
 * has said (an assumption):
 *
 *   starting cash      the cash ledger's liquid accounts in the base currency, today
 *   money coming in    open invoices, on the due date plus the customer's own average lateness when at least three of their
 *                      invoices have been settled (otherwise on the due date); already-late invoices on the next day, flagged
 *   money going out    open bills on their due date (late ones on the next day, flagged); recurring rules; tax payments and
 *                      payroll when those modules supply them (always labelled estimates); nothing is invented when they do not
 *   assumptions        what-ifs from a scenario, applied here and nowhere else
 *
 * It is a projection, not a promise: every line says where it came from and how its date was chosen. Nothing here writes
 * to the books.
 */
class CashProjection
{
    public const HORIZONS = ['weeks_13' => '13 weeks', 'months_12' => '12 months'];

    public function __construct(
        private readonly OpenItemsProvider $items,
        private readonly TaxObligationsProvider $tax,
        private readonly PayrollCommitmentsProvider $payroll,
        private readonly CashPosition $position,
        private readonly TransactionRecurrenceService $schedule,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $assumptions  from Assumptions::normalise()
     * @return array<string, mixed>
     */
    public function project(LedgerContext $context, LedgerSetting $settings, CarbonImmutable $today, string $horizon = 'weeks_13', array $assumptions = [], int $bufferCents = 0): array
    {
        $today = $today->startOfDay();
        $end = $horizon === 'months_12' ? $today->addMonthsNoOverflow(12)->subDay() : $today->addDays(90);
        $base = $settings->base_currency;
        $tomorrow = $today->addDay();
        $minPaid = (int) config('reporting.projection.lateness_min_paid_invoices', 3);

        $pos = $this->position->now($context, $settings);
        $opening = $pos['available_cents'];
        $events = [];
        $excluded = [];

        $by = fn (string $type) => array_values(array_filter($assumptions, fn ($a) => $a['type'] === $type));
        $sooner = array_sum(array_column($by('collect_faster'), 'days'));
        $later = array_sum(array_column($by('delay_receipts'), 'days'));
        $payLater = array_sum(array_column($by('delay_payment'), 'days'));

        // --- money coming in: open invoices ---------------------------------------------------
        $history = $this->items->settlementHistory($context, 'receivable', $today);
        $credits = 0;
        foreach ($this->items->openItems($context, 'receivable', $today) as $item) {
            if ($item->openCents <= 0) {
                $credits += -$item->openCents;

                continue;
            }
            $due = CarbonImmutable::parse($item->dueOn);
            $h = $item->partyId !== null ? ($history[$item->partyId] ?? null) : null;
            $lag = $h !== null && $h['settled'] >= $minPaid ? max(0, $h['avg_days_late']) : 0;
            $method = $lag > 0 ? "due date plus {$lag} days: this customer has paid {$h['settled']} invoices on average {$h['avg_days_late']} days after the due date" : ($h !== null && $h['settled'] >= $minPaid ? 'due date: this customer usually pays on time' : 'due date (fewer than '.$minPaid.' settled invoices to learn from)');
            $date = $due->addDays($lag);
            if ($sooner > 0) {
                $date = $date->subDays($sooner);
                $method .= "; {$sooner} days sooner (assumption)";
            }
            if ($later > 0) {
                $date = $date->addDays($later);
                $method .= "; {$later} days later (assumption)";
            }
            $overdue = $date->lt($today);
            if ($overdue) {
                $date = $tomorrow;
                $method .= '; already late, assumed received tomorrow: not guaranteed';
            }
            $events[] = $this->event($date, $item->partyName.' '.$item->reference, $item->openCents, 'invoice', false, $overdue, $method, $item->href);
        }
        if ($credits > 0) {
            $excluded[] = 'Unapplied customer credits of '.Money::format($credits, $base).' are not netted against invoices.';
        }

        // --- money going out: open bills ------------------------------------------------------
        foreach ($this->items->openItems($context, 'payable', $today) as $item) {
            if ($item->openCents <= 0) {
                continue;
            }
            $date = CarbonImmutable::parse($item->dueOn);
            $method = 'due date';
            if ($payLater > 0) {
                $date = $date->addDays($payLater);
                $method .= "; paid {$payLater} days later (assumption)";
            }
            $overdue = $date->lt($today);
            if ($overdue) {
                $date = $tomorrow;
                $method .= '; already due, assumed paid tomorrow';
            }
            $events[] = $this->event($date, $item->partyName.' '.$item->reference, -$item->openCents, 'bill', false, $overdue, $method, $item->href);
        }

        // --- recurring rules ------------------------------------------------------------------
        $liquid = RecurringRule::query()->forContext($context)->whereHas('account', fn ($q) => $q->forContext($context)->whereIn('type', CashPosition::LIQUID)->whereNull('accounts.deleted_at'))->with('account')->get();
        $change = [];
        foreach ($by('change_recurring') as $a) {
            $change[$a['direction']] = ($change[$a['direction']] ?? 0) + $a['bp'];
        }
        foreach ($liquid as $rule) {
            if ($rule->isEnded() || $rule->isPaused()) {
                continue;
            }
            if ($rule->currency !== $base) {
                $excluded[] = "Recurring \"{$rule->description}\" is in {$rule->currency} and is not projected.";

                continue;
            }
            $amount = $rule->amount_cents;
            $note = 'recurring rule';
            $bp = $change[$rule->type === 'income' ? 'income' : 'expense'] ?? 0;
            if ($bp !== 0) {
                $amount = Money::mulDivRound($amount, max(0, 10000 + $bp), 10000);
                $note .= '; amount changed by '.Assumptions::bpText($bp).' (assumption)';
            }
            $date = $rule->next_occurrence_date?->copy();
            $guard = 0;
            while ($date !== null && $date->lte(Carbon::parse($end->toDateString())) && $guard++ < 400) {
                if ($rule->end_date !== null && $date->gt($rule->end_date)) {
                    break;
                }
                $d = CarbonImmutable::parse($date->toDateString());
                $events[] = $this->event($d->lt($today) ? $today : $d, $rule->description, $rule->type === 'income' ? $amount : -$amount, 'recurring', false, $d->lt($today), $note.($d->lt($today) ? '; due but not generated yet' : ''), null);
                $date = $this->schedule->nextAfter($rule->start_date, $rule->recurrence_type, $rule->recurrence_interval, $date);
            }
        }

        // --- tax and payroll from their modules (never invented) -------------------------------
        $taxRows = $this->tax->obligations($context, $today->toDateString(), $end->toDateString());
        foreach ($taxRows as $t) {
            $events[] = $this->event(CarbonImmutable::parse($t['date']), $t['label'], -abs($t['amount_cents']), 'tax', $t['kind'] !== 'calculated', false, 'tax calendar, '.$t['kind'], $t['href'] ?? null);
        }
        $payRows = $this->payroll->commitments($context, $today->toDateString(), $end->toDateString());
        foreach ($payRows as $p) {
            $events[] = $this->event(CarbonImmutable::parse($p['date']), $p['label'], -abs($p['amount_cents']), 'payroll', $p['kind'] !== 'calculated', false, 'payroll, '.$p['kind'], null);
        }
        $notes = [];
        if ($taxRows === []) {
            $notes[] = 'No tax payments are included: no tax calendar or estimate is connected, and none is invented. Tax due in this window is NOT reflected in the projected balance.';
        }
        if ($payRows === []) {
            $notes[] = 'No payroll is included: no payroll commitments are recorded. Salaries and levies due in this window are NOT reflected unless they are recurring rules.';
        }

        // --- assumptions that add money ---------------------------------------------------------
        foreach ($by('new_cost') as $a) {
            $d = CarbonImmutable::parse($a['start_on']);
            for ($k = 0; $d->lte($end) && ($a['months'] === null || $k < $a['months']); $k++) {
                if ($d->gte($today)) {
                    $events[] = $this->event($d, $a['name'], -$a['amount_cents'], 'assumption', true, false, 'new monthly cost (assumption)', null);
                }
                $d = CarbonImmutable::parse($a['start_on'])->addMonthsNoOverflow($k + 1);
            }
        }
        foreach ($by('one_off') as $a) {
            $d = CarbonImmutable::parse($a['on']);
            if ($d->gte($today) && $d->lte($end)) {
                $events[] = $this->event($d, $a['name'], $a['direction'] === 'in' ? $a['amount_cents'] : -$a['amount_cents'], 'assumption', true, false, 'one-off (assumption)', null);
            } else {
                $notes[] = "\"{$a['name']}\" is outside the projection window and is ignored.";
            }
        }

        $events = array_values(array_filter($events, fn ($e) => $e['date'] <= $end->toDateString()));
        usort($events, fn ($a, $b) => [$a['date'], -$a['cents'], $a['label']] <=> [$b['date'], -$b['cents'], $b['label']]);

        // --- running balance -------------------------------------------------------------------
        $balance = $opening;
        $series = [['date' => $today->toDateString(), 'balance_cents' => $opening]];
        $lowest = ['date' => $today->toDateString(), 'balance_cents' => $opening];
        $shortage = $opening < $bufferCents ? ['date' => $today->toDateString(), 'balance_cents' => $opening] : null;
        $inflow = 0;
        $outflow = 0;
        $i = 0;
        $n = count($events);
        while ($i < $n) {
            $date = $events[$i]['date'];
            while ($i < $n && $events[$i]['date'] === $date) {
                $balance += $events[$i]['cents'];
                $events[$i]['balance_cents'] = null;
                $events[$i]['cents'] > 0 ? $inflow += $events[$i]['cents'] : $outflow -= $events[$i]['cents'];
                $i++;
            }
            for ($j = $i - 1; $j >= 0 && $events[$j]['date'] === $date; $j--) {
                $events[$j]['balance_cents'] = $balance;
            }
            $series[] = ['date' => $date, 'balance_cents' => $balance];
            if ($balance < $lowest['balance_cents']) {
                $lowest = ['date' => $date, 'balance_cents' => $balance];
            }
            if ($shortage === null && $balance < $bufferCents) {
                $shortage = ['date' => $date, 'balance_cents' => $balance];
            }
        }
        $series[] = ['date' => $end->toDateString(), 'balance_cents' => $balance];

        return [
            'today' => $today->toDateString(), 'end' => $end->toDateString(), 'horizon' => $horizon, 'currency' => $base,
            'opening_cents' => $opening, 'closing_cents' => $balance, 'inflow_cents' => $inflow, 'outflow_cents' => $outflow,
            'lowest' => $lowest, 'shortage' => $shortage, 'buffer_cents' => $bufferCents,
            'position' => $pos, 'events' => $events, 'series' => $series, 'buckets' => $this->buckets($events, $today, $end, $horizon, $opening),
            'excluded' => $excluded, 'notes' => $notes, 'assumptions' => array_map(fn ($a) => Assumptions::describe($a, $base), $assumptions),
            'source' => $this->items->source(),
        ];
    }

    /** A summary of how a scenario's projection differs from the base projection. @return array<string, mixed> */
    public function compare(array $base, array $scenario): array
    {
        return [
            'closing_diff_cents' => $scenario['closing_cents'] - $base['closing_cents'],
            'lowest_diff_cents' => $scenario['lowest']['balance_cents'] - $base['lowest']['balance_cents'],
            'inflow_diff_cents' => $scenario['inflow_cents'] - $base['inflow_cents'],
            'outflow_diff_cents' => $scenario['outflow_cents'] - $base['outflow_cents'],
            'base_shortage' => $base['shortage'], 'scenario_shortage' => $scenario['shortage'],
        ];
    }

    /**
     * When a scenario leaves the projection exactly as it was, say why in plain words. Null when it does change something.
     * The reasons come from the base projection's own items: how many invoices, bills and recurring items fall in the window,
     * and whether the ones that do are already late (a late item is placed on tomorrow, whatever days a scenario adds).
     *
     * @param  list<array<string, mixed>>  $assumptions  from Assumptions::normalise()
     */
    public function noEffectReason(array $assumptions, array $base, array $scenario): ?string
    {
        $key = fn (array $p) => json_encode([$p['series'], array_map(fn ($e) => [$e['date'], $e['cents'], $e['label']], $p['events'])]);
        if ($key($base) !== $key($scenario)) {
            return null;
        }

        $of = fn (string $source) => array_values(array_filter($base['events'], fn ($e) => $e['source'] === $source));
        $late = fn (array $rows) => count(array_filter($rows, fn ($e) => $e['overdue']));
        $window = CarbonImmutable::parse($base['today'])->format('j M Y').' and '.CarbonImmutable::parse($base['end'])->format('j M Y');
        $reasons = [];

        foreach (array_unique(array_column($assumptions, 'type')) as $type) {
            $reasons[] = match ($type) {
                'collect_faster', 'delay_receipts' => (function () use ($of, $late, $window) {
                    $rows = $of('invoice');

                    return $rows === []
                        ? "no invoice is expected to be paid between {$window}, so there is no payment to move"
                        : ($late($rows) === count($rows) ? 'every open invoice is already overdue, and an overdue invoice is assumed paid tomorrow whatever days are added' : 'the invoices that are open land in the same days either way');
                })(),
                'delay_payment' => (function () use ($of, $late, $window) {
                    $rows = $of('bill');

                    return $rows === []
                        ? "no supplier bill falls due between {$window}, so there is no payment to delay"
                        : ($late($rows) === count($rows) ? 'every open bill is already overdue, and an overdue bill is assumed paid tomorrow whatever days are added' : 'the bills that are open land in the same days either way');
                })(),
                'change_recurring' => $of('recurring') === [] ? 'no recurring item falls in this window, so there is nothing to reprice' : 'the recurring items it changes are not in this window',
                'new_cost', 'one_off' => 'what it adds falls outside the window',
                default => null,
            };
        }

        $reasons = array_values(array_filter($reasons));

        return $reasons === []
            ? 'It moves no money inside the window.'
            : ucfirst(implode('; ', $reasons)).'.';
    }

    /** @return list<array<string, mixed>> */
    private function buckets(array $events, CarbonImmutable $today, CarbonImmutable $end, string $horizon, int $opening): array
    {
        $buckets = [];
        $cursor = $today;
        $balance = $opening;
        $i = 0;
        for ($k = 0; $cursor->lte($end); $k++) {
            $next = $horizon === 'months_12' ? $today->addMonthsNoOverflow($k + 1) : $today->addDays(7 * ($k + 1));
            $to = $next->subDay()->gt($end) ? $end : $next->subDay();
            $in = 0;
            $out = 0;
            foreach ($events as $e) {
                if ($e['date'] >= $cursor->toDateString() && $e['date'] <= $to->toDateString()) {
                    $e['cents'] > 0 ? $in += $e['cents'] : $out -= $e['cents'];
                }
            }
            $balance += $in - $out;
            $buckets[] = [
                'label' => $horizon === 'months_12' ? $cursor->format('j M').' to '.$to->format('j M Y') : 'Week '.($k + 1).': '.$cursor->format('j M').' to '.$to->format('j M'),
                'from' => $cursor->toDateString(), 'to' => $to->toDateString(), 'inflow_cents' => $in, 'outflow_cents' => $out, 'closing_cents' => $balance,
            ];
            $cursor = $next;
        }

        return $buckets;
    }

    /** @return array<string, mixed> */
    private function event(CarbonImmutable $date, string $label, int $cents, string $source, bool $estimate, bool $overdue, string $method, ?string $href): array
    {
        return ['date' => $date->toDateString(), 'label' => trim($label), 'cents' => $cents, 'source' => $source, 'estimate' => $estimate, 'overdue' => $overdue, 'method' => $method, 'href' => $href];
    }
}
