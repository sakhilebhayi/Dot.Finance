<?php

namespace App\Services\Purchases;

use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * What we owe one supplier over a period: the opening balance, every bill, credit note and payment in the period, and the closing
 * balance, by currency. It is built from the documents and payments (not from the books), so it can be compared with the supplier's own
 * statement line by line. A positive balance is owed to the supplier.
 */
final class SupplierStatement
{
    /**
     * @return array{party: Party, from: string, to: string, currencies: array<string, array{opening: int, lines: list<array<string, mixed>>, closing: int}>}
     */
    public function forSupplier(Party $party, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return LedgerContext::run($party->ledgerContext(), function () use ($party, $from, $to) {
            $events = [];

            Bill::query()->where('party_id', $party->id)->where(fn ($q) => $q->whereIn('status', Bill::POSTED)->orWhere(fn ($w) => $w->where('status', 'void')->whereNotNull('posted_at')))->get()->each(function (Bill $b) use (&$events) {
                $sign = $b->isCreditNote() ? -1 : 1;
                $events[] = ['date' => $b->bill_date->toDateString(), 'currency' => $b->currency, 'kind' => $b->kindLabel(), 'reference' => $b->displayNumber().($b->supplier_reference ? ' / '.$b->supplier_reference : ''), 'amount' => $sign * $b->total_cents, 'sort' => 1, 'route' => 'purchases.bills.show', 'id' => $b->id];
                if ($b->status === 'void' && $b->posted_at !== null) {
                    $events[] = ['date' => $b->voided_at->toDateString(), 'currency' => $b->currency, 'kind' => 'Voided', 'reference' => $b->displayNumber(), 'amount' => -$sign * $b->total_cents, 'sort' => 2, 'route' => 'purchases.bills.show', 'id' => $b->id];
                }
            });

            ExpenseClaim::query()->where('party_id', $party->id)->whereIn('status', [...Bill::POSTED, 'void'])->whereNotNull('posted_at')->get()->each(function (ExpenseClaim $c) use (&$events) {
                $events[] = ['date' => $c->claim_date->toDateString(), 'currency' => $c->currency, 'kind' => 'Expense claim', 'reference' => $c->displayNumber(), 'amount' => $c->total_cents, 'sort' => 1, 'route' => 'purchases.claims.show', 'id' => $c->id];
                if ($c->status === 'void') {
                    $events[] = ['date' => $c->voided_at->toDateString(), 'currency' => $c->currency, 'kind' => 'Voided', 'reference' => $c->displayNumber(), 'amount' => -$c->total_cents, 'sort' => 2, 'route' => 'purchases.claims.show', 'id' => $c->id];
                }
            });

            Payment::query()->where('party_id', $party->id)->where('direction', 'out')->get()->each(function (Payment $p) use (&$events) {
                $events[] = ['date' => $p->paid_on->toDateString(), 'currency' => $p->currency, 'kind' => 'Payment', 'reference' => $p->reference ?? '#'.$p->id, 'amount' => -$p->amount_cents, 'sort' => 3, 'route' => 'purchases.payments.show', 'id' => $p->id];
                if ($p->isReversed() && $p->reversed_at !== null) {
                    $events[] = ['date' => $p->reversed_at->toDateString(), 'currency' => $p->currency, 'kind' => 'Payment reversed', 'reference' => $p->reference ?? '#'.$p->id, 'amount' => $p->amount_cents, 'sort' => 4, 'route' => 'purchases.payments.show', 'id' => $p->id];
                }
            });

            usort($events, fn ($a, $b) => [$a['date'], $a['sort'], $a['id']] <=> [$b['date'], $b['sort'], $b['id']]);

            $currencies = [];
            foreach ($events as $e) {
                $c = &$currencies[$e['currency']];
                $c ??= ['opening' => 0, 'lines' => [], 'closing' => 0];

                if ($e['date'] < $from->toDateString()) {
                    $c['opening'] += $e['amount'];
                } elseif ($e['date'] <= $to->toDateString()) {
                    $c['lines'][] = $e;
                }
                unset($c);
            }

            foreach ($currencies as &$c) {
                $running = $c['opening'];
                foreach ($c['lines'] as &$line) {
                    $running += $line['amount'];
                    $line['balance'] = $running;
                }
                unset($line);
                $c['closing'] = $running;
            }
            unset($c);

            return ['party' => $party, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'currencies' => $currencies];
        });
    }
}
