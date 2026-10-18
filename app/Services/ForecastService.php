<?php

namespace App\Services;

use App\Models\Account;
use App\Models\RecurringRule;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Carbon;

/**
 * A projection of each currency's balance over the next 90 days, built from
 * recurring items ONLY.
 *
 * It is arithmetic, not a prediction: it takes today's actual ledger balance and
 * applies every occurrence of every active recurring rule that falls inside the
 * horizon. One-off spending, income that is not set up as recurring, changes in
 * amounts and anything else the future holds are all absent, and the page says
 * so. Nothing here writes to the ledger and nothing here feeds back into the
 * actuals shown elsewhere.
 */
class ForecastService
{
    public const HORIZON_DAYS = 90;

    public function __construct(private readonly TransactionRecurrenceService $schedule = new TransactionRecurrenceService) {}

    /**
     * @return array{
     *     from: string, to: string, days: int, included_rules: int,
     *     excluded: list<array{description: string, reason: string}>,
     *     currencies: array<string, array<string, mixed>>
     * }
     */
    public function project(LedgerContext $context, ?Carbon $from = null, int $days = self::HORIZON_DAYS): array
    {
        $from = ($from ?? now())->copy()->startOfDay();
        $to = $from->copy()->addDays($days);

        $accounts = Account::query()->forContext($context)->withBalance()->orderBy('name')->get();

        $currencies = [];
        foreach ($accounts as $account) {
            $currencies[$account->currency] ??= [
                'currency' => $account->currency,
                'start_cents' => 0, 'end_cents' => 0, 'lowest_cents' => 0, 'lowest_date' => null,
                'income_cents' => 0, 'expense_cents' => 0, 'events' => [], 'series' => [], 'accounts' => [],
            ];
            $currencies[$account->currency]['start_cents'] += $account->balanceCents();
            $currencies[$account->currency]['accounts'][$account->id] = [
                'name' => $account->name, 'start_cents' => $account->balanceCents(), 'end_cents' => $account->balanceCents(),
            ];
        }

        $rules = RecurringRule::query()->forContext($context)
            ->whereHas('account', fn ($q) => $q->forContext($context)->whereNull('accounts.deleted_at'))
            ->with(['account', 'category'])
            ->get();

        $excluded = [];
        $included = 0;
        $events = [];

        foreach ($rules as $rule) {
            if ($rule->isEnded()) {
                continue;
            }

            if ($rule->isPaused()) {
                $excluded[] = ['description' => $rule->description, 'reason' => 'paused'];

                continue;
            }

            $included++;

            foreach ($this->occurrences($rule, $from, $to) as [$date, $overdue]) {
                $events[] = [
                    'date' => $date->toDateString(),
                    'overdue' => $overdue,
                    'description' => $rule->description,
                    'account_id' => $rule->account_id,
                    'account' => $rule->account?->name,
                    'currency' => $rule->currency,
                    'signed_cents' => $rule->type === 'income' ? $rule->amount_cents : -$rule->amount_cents,
                    'rule_id' => $rule->id,
                ];
            }
        }

        usort($events, fn ($a, $b) => [$a['date'], $a['rule_id']] <=> [$b['date'], $b['rule_id']]);

        foreach ($currencies as $code => &$entry) {
            $balance = $entry['start_cents'];
            $entry['lowest_cents'] = $balance;
            $entry['lowest_date'] = $from->toDateString();
            $entry['series'][] = ['date' => $from->toDateString(), 'balance_cents' => $balance];

            foreach ($events as $event) {
                if ($event['currency'] !== $code) {
                    continue;
                }

                $balance += $event['signed_cents'];
                $event['balance_cents'] = $balance;
                $entry['events'][] = $event;
                $entry['series'][] = ['date' => $event['date'], 'balance_cents' => $balance];

                $event['signed_cents'] > 0 ? $entry['income_cents'] += $event['signed_cents'] : $entry['expense_cents'] -= $event['signed_cents'];

                if (isset($entry['accounts'][$event['account_id']])) {
                    $entry['accounts'][$event['account_id']]['end_cents'] += $event['signed_cents'];
                }

                if ($balance < $entry['lowest_cents']) {
                    $entry['lowest_cents'] = $balance;
                    $entry['lowest_date'] = $event['date'];
                }
            }

            $entry['end_cents'] = $balance;
            $entry['series'][] = ['date' => $to->toDateString(), 'balance_cents' => $balance];
        }
        unset($entry);

        ksort($currencies);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'included_rules' => $included,
            'excluded' => $excluded,
            'currencies' => $currencies,
        ];
    }

    /**
     * Dates a rule will fire inside [$from, $to], respecting its end date. An
     * occurrence already due but not yet generated lands on $from and is flagged.
     *
     * @return list<array{0: Carbon, 1: bool}>
     */
    private function occurrences(RecurringRule $rule, Carbon $from, Carbon $to): array
    {
        $out = [];
        $date = $rule->next_occurrence_date?->copy();

        while ($date !== null && $date->lte($to)) {
            if ($rule->end_date !== null && $date->gt($rule->end_date)) {
                break;
            }

            $overdue = $date->lt($from);
            $out[] = [$overdue ? $from->copy() : $date->copy(), $overdue];

            $date = $this->schedule->nextAfter($rule->start_date, $rule->recurrence_type, $rule->recurrence_interval, $date);
        }

        return $out;
    }
}
