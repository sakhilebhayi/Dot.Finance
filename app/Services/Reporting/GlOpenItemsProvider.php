<?php

namespace App\Services\Reporting;

use App\Contracts\Reporting\OpenItem;
use App\Contracts\Reporting\OpenItemsProvider;
use App\Models\Accounting\GlAccount;
use App\Models\Shared\Party;
use App\Services\Accounting\GlQuery;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Open items derived from the ledger itself: the lines on the receivable (or payable) control account, per customer
 * (or supplier), oldest first. Money settling the account is matched against the oldest unpaid amounts first (first in,
 * first out), which is how a statement is normally read; whatever is left is what is still owed. A credit with nothing to
 * settle is shown as an unapplied credit (a negative item). The due date is the entry date plus the party's payment terms.
 *
 * This is a method, not a document register: it cannot know that a payment was meant for a particular invoice, only that
 * the account balance for the party is what it is. When Sales and Purchases bind their own provider the dates and
 * references come from the documents, and the report's "ties to the ledger" check keeps the two honest.
 */
class GlOpenItemsProvider implements OpenItemsProvider
{
    public function __construct(private readonly GlQuery $query) {}

    public function openItems(LedgerContext $context, string $kind, CarbonImmutable $asOf): array
    {
        return $this->walk($context, $kind, $asOf)['items'];
    }

    public function settlementHistory(LedgerContext $context, string $kind, CarbonImmutable $asOf): array
    {
        return $this->walk($context, $kind, $asOf)['history'];
    }

    public function source(): string
    {
        return 'Derived from the receivable and payable accounts in the ledger, oldest amount settled first. Due dates are the entry date plus the customer or supplier\'s payment terms.';
    }

    /** @return array{items: list<OpenItem>, history: array<int, array{settled: int, avg_days_late: int}>} */
    private function walk(LedgerContext $context, string $kind, CarbonImmutable $asOf): array
    {
        $key = $kind === 'payable' ? 'ap' : 'ar';
        $control = GlAccount::query()->forContext($context)->where('system_key', $key)->first();
        if ($control === null) {
            return ['items' => [], 'history' => []];
        }

        $sign = $kind === 'payable' ? -1 : 1;
        $base = $this->query->ownedLines($context)->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.gl_account_id', $control->id)->where('l.entry_date', '<=', $asOf->toDateString());

        // The control account of a busy ledger holds hundreds of thousands of lines. They are read one customer at a time
        // (ordered by customer, then in posting order) from a cursor, never all at once; only the customer being worked on is in memory.
        $partyIds = (clone $base)->whereNotNull('l.party_id')->distinct()->pluck('l.party_id')->map(fn ($id) => (int) $id)->all();
        $parties = collect();
        foreach (array_chunk($partyIds, 2000) as $chunk) {
            $parties = $parties->concat(Party::query()->forContext($context)->whereIn('id', $chunk)->get());
        }
        $parties = $parties->keyBy('id');
        $currency = $control->currency ?? 'ZAR';

        $items = [];
        $history = [];

        $settle = function (int $partyId, array $partyLines) use (&$items, &$history, $kind, $sign, $parties, $currency, $asOf) {
            $party = $partyId === 0 ? null : $parties->get($partyId);
            $terms = (int) ($party->payment_terms_days ?? 0);
            $name = $party?->name ?? ($kind === 'payable' ? 'No supplier recorded' : 'No customer recorded');
            $open = [];
            $credit = 0;
            $settled = 0;
            $lateDays = 0;

            foreach ($partyLines as $l) {
                $date = substr((string) $l->entry_date, 0, 10);
                $amount = $sign * ((int) $l->debit_cents - (int) $l->credit_cents);

                if ($amount > 0) {
                    $use = min($amount, $credit);
                    $credit -= $use;
                    $amount -= $use;
                    if ($amount > 0) {
                        $open[] = ['issued' => $date, 'due' => CarbonImmutable::parse($date)->addDays($terms)->toDateString(), 'left' => $amount, 'ref' => 'JE-'.str_pad((string) $l->entry_number, 6, '0', STR_PAD_LEFT).' '.mb_substr((string) $l->memo, 0, 60), 'entry' => $l->entry_id];
                    }

                    continue;
                }

                $money = -$amount;
                while ($money > 0 && $open !== []) {
                    $take = min($money, $open[0]['left']);
                    $open[0]['left'] -= $take;
                    $money -= $take;
                    if ($open[0]['left'] === 0) {
                        $settled++;
                        $lateDays += (int) CarbonImmutable::parse($open[0]['due'])->diffInDays(CarbonImmutable::parse($date), false);
                        array_shift($open);
                    }
                }
                $credit += $money;
            }

            foreach ($open as $o) {
                $items[] = new OpenItem($partyId === 0 ? null : $partyId, $name, $o['ref'], $o['issued'], $o['due'], $o['left'], $currency, route('accounting.journals.show', $o['entry']));
            }
            if ($credit > 0) {
                $items[] = new OpenItem($partyId === 0 ? null : $partyId, $name, 'Unapplied '.($kind === 'payable' ? 'payment' : 'receipt'), $asOf->toDateString(), $asOf->toDateString(), -$credit, $currency);
            }
            if ($partyId !== 0 && $settled > 0) {
                $history[$partyId] = ['settled' => $settled, 'avg_days_late' => intdiv($lateDays, $settled)];
            }
        };

        $current = null;
        $buffer = [];
        $rows = (clone $base)->orderByRaw('COALESCE(l.party_id, 0)')->orderBy('l.entry_date')->orderBy('e.entry_number')->orderBy('l.line_no')
            ->select(['l.party_id', 'l.entry_date', 'l.debit_cents', 'l.credit_cents', 'e.id as entry_id', 'e.entry_number', 'e.memo'])->cursor();
        foreach ($rows as $line) {
            $partyId = (int) ($line->party_id ?? 0);
            if ($current !== null && $partyId !== $current) {
                $settle($current, $buffer);
                $buffer = [];
            }
            $current = $partyId;
            $buffer[] = $line;
        }
        if ($current !== null) {
            $settle($current, $buffer);
        }

        return ['items' => $items, 'history' => $history];
    }
}
