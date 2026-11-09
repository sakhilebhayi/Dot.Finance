<?php

namespace App\Services\Bank;

use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankRule;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The exceptions inbox: statement lines nothing in the books explains, grouped by their LIKELY CAUSE so a person decides
 * once per group instead of once per line. Each line lands in exactly one group, the first that fits, in this order:
 *
 *   possible_duplicate  the same date, amount and words are already held from another statement
 *   transfer            an opposite line of the same amount, same days, in another of your accounts
 *   documents           an open invoice or bill (when Sales / Purchases are connected) fits the narrative or amount
 *   close_amount        an entry with the same words and date but a different amount (a short payment, a deducted fee)
 *   timing              an entry with exactly this amount, dated outside the match window
 *   rule                one of your bank rules fits (the rule is named)
 *   bank_charge         the words of a fee or charge
 *   interest            the word interest
 *   money_in / money_out nothing else explains it
 *
 * The group says why it was formed in words; the actions (create the entry, make the transfer, match the entry you
 * pointed at) are performed by LineActions through the normal posting path. Pure reading: this class writes nothing.
 */
class ExceptionClassifier
{
    public const ORDER = ['possible_duplicate', 'transfer', 'documents', 'close_amount', 'timing', 'rule', 'bank_charge', 'interest', 'money_in', 'money_out'];

    private const CHARGE = '/\b(fees?|charges?|commission|service charge|account fee|admin(istration)? fee|bank charges|cash handling|sms notif\w*|swift|subscription fee|monthly (account|admin|fee))\b/i';

    private const TITLES = [
        'possible_duplicate' => ['Possible duplicates', 'The same date, amount and words are already held from another statement. Check you did not import an overlapping export twice.'],
        'transfer' => ['Looks like a transfer between your accounts', 'The same amount left one of your accounts and arrived in another within a few days. Record it once as a transfer and both sides match.'],
        'documents' => ['May settle an invoice or bill', 'An open invoice or bill fits the narrative or the amount. Settling it records the payment against the document.'],
        'close_amount' => ['Close to an entry you already have', 'There is an entry with the same words and date but a different amount: a short payment or a fee taken off. Match it and record the difference.'],
        'timing' => ['Same amount, different date', 'An entry with exactly this amount exists but is dated well away from the bank date. Match it if it is the same event.'],
        'rule' => ['Suggested by your rules', 'One of your bank rules fits these lines. Confirm to create the entries with the rule\'s category.'],
        'bank_charge' => ['Bank charges', 'These read like fees. Record them as bank charges in one step.'],
        'interest' => ['Interest', 'These mention interest. Record them as interest in one step.'],
        'money_in' => ['Money in that is not in your books', 'Nothing in your books explains these deposits. Create an income entry, or match an entry you forgot to link.'],
        'money_out' => ['Money out that is not in your books', 'Nothing in your books explains these payments. Create an expense entry, or match an entry you forgot to link.'],
    ];

    public function __construct(private readonly DocumentSources $documents, private readonly MatchingService $matching) {}

    /**
     * @param  Collection<int, object>  $lines  open statement lines still unmatched and not already proposed (id, posted_on, amount_cents, description, reference, bank_statement_id)
     * @param  Collection<int, object>  $entries  the account's uncleared candidate rows (id, date, signed, description) as loaded by MatchingService
     * @return list<array<string, mixed>>
     */
    public function classify(BankReconciliation $session, Collection $lines, Collection $entries): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        $context = $session->ledgerContext();
        $account = Account::query()->forContext($context)->withTrashed()->findOrFail($session->account_id);

        $prepared = [];
        foreach ($lines as $line) {
            $prepared[(int) $line->id] = MatchText::prepare($line->description, $line->reference) + ['day' => MatchText::day(substr((string) $line->posted_on, 0, 10)), 'cents' => (int) $line->amount_cents];
        }

        $duplicates = $this->duplicates($account, $lines);
        $partners = $this->transferPartners($session, $account, $lines);
        $documents = $this->documents->available() ? $this->documents->candidates($context, array_map(fn ($l) => ['id' => (int) $l->id, 'cents' => (int) $l->amount_cents, 'currency' => $account->currency, 'text' => $prepared[(int) $l->id]['text'], 'docs' => $prepared[(int) $l->id]['docs']], $lines->all())) : [];
        $wide = $this->wideEntries($session, $account, $lines);
        $rules = BankRuleEngine::for($context, $account->id);

        $groups = [];
        foreach (self::ORDER as $key) {
            $groups[$key] = ['key' => $key, 'title' => self::TITLES[$key][0], 'why' => self::TITLES[$key][1], 'lines' => [], 'count' => 0, 'total_cents' => 0];
        }

        foreach ($lines as $line) {
            $id = (int) $line->id;
            $p = $prepared[$id];
            $cents = $p['cents'];
            $hint = null;
            $extra = [];

            if (isset($duplicates[$id])) {
                $key = 'possible_duplicate';
                $hint = 'Another line with the same date, amount and description is held from a different statement.';
            } elseif (isset($partners[$id])) {
                $key = 'transfer';
                $partner = $partners[$id];
                $hint = ($cents < 0 ? 'Money left here and arrived in ' : 'Money arrived here and left ').$partner['account_name'].' on '.$partner['date'].' ('.Money::format(abs($cents), $account->currency).').';
                $extra = ['partner' => $partner];
            } elseif (! empty($documents[$id])) {
                $key = 'documents';
                $best = $documents[$id][0];
                $hint = $best->number.' ('.$best->partyName.'): '.$best->why;
                $extra = ['documents' => $documents[$id]];
            } elseif (($close = $this->closeAmount($p, $wide)) !== null) {
                $key = 'close_amount';
                $hint = 'Entry "'.$close['description'].'" on '.$close['date'].' is '.Money::format(abs($close['signed']), $account->currency).', which is '.Money::format(abs(abs($cents) - abs($close['signed'])), $account->currency).' '.(abs($cents) > abs($close['signed']) ? 'less' : 'more').' than the bank shows.';
                $extra = ['candidate' => $close];
            } elseif (($timing = $this->timing($p, $wide)) !== null) {
                $key = 'timing';
                $hint = 'Entry "'.$timing['description'].'" has exactly this amount but is dated '.$timing['date'].' ('.abs($timing['day'] - $p['day']).' days from the bank date).';
                $extra = ['candidate' => $timing];
            } elseif (($rule = $rules->match($line)) instanceof BankRule) {
                $key = 'rule';
                $hint = $rule->sentence().' then '.($rule->category ? 'categorise as '.$rule->category->name : 'record it').'.';
                $extra = ['rule' => $rule];
            } elseif ($cents < 0 && preg_match(self::CHARGE, (string) $line->description) === 1) {
                $key = 'bank_charge';
            } elseif (preg_match('/\binterest\b/i', (string) $line->description) === 1) {
                $key = 'interest';
            } else {
                $key = $cents > 0 ? 'money_in' : 'money_out';
            }

            $groups[$key]['count']++;
            $groups[$key]['total_cents'] += $cents;
            $groups[$key]['lines'][] = ['line' => $line, 'hint' => $hint] + $extra;
        }

        return array_values(array_filter($groups, fn ($g) => $g['count'] > 0));
    }

    /**
     * Lines with an identical twin (date, amount, lower-cased description) held from a different statement.
     *
     * @return array<int, true>
     */
    private function duplicates(Account $account, Collection $lines): array
    {
        $from = $lines->min(fn ($l) => substr((string) $l->posted_on, 0, 10));
        $to = $lines->max(fn ($l) => substr((string) $l->posted_on, 0, 10));

        $rows = DB::table('bank_statement_lines')->where('account_id', $account->id)->whereBetween('posted_on', [$from, $to])
            ->get(['id', 'posted_on', 'amount_cents', 'description', 'bank_statement_id']);

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->posted_on.'|'.$row->amount_cents.'|'.BankRuleEngine::normalise((string) $row->description)][] = $row;
        }

        $out = [];
        $open = $lines->keyBy('id');
        foreach ($groups as $members) {
            if (count($members) < 2 || count(array_unique(array_column($members, 'bank_statement_id'))) < 2) {
                continue;
            }
            foreach ($members as $m) {
                if ($open->has($m->id)) {
                    $out[(int) $m->id] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Another of the ledger's accounts, in the same currency, holding an unmatched line of the opposite amount within three days.
     *
     * @return array<int, array{line_id: int, account_id: int, account_name: string, date: string}>
     */
    private function transferPartners(BankReconciliation $session, Account $account, Collection $lines): array
    {
        $from = date('Y-m-d', strtotime($lines->min(fn ($l) => substr((string) $l->posted_on, 0, 10)).' -3 days'));
        $to = date('Y-m-d', strtotime($lines->max(fn ($l) => substr((string) $l->posted_on, 0, 10)).' +3 days'));

        $others = Account::query()->forContext($session->ledgerContext())->where('id', '<>', $account->id)->where('currency', $account->currency)->pluck('name', 'id');

        if ($others->isEmpty()) {
            return [];
        }

        $candidates = DB::table('bank_statement_lines')->whereIn('account_id', $others->keys()->all())
            ->whereNull('bank_match_id')->whereNull('reconciliation_id')->whereBetween('posted_on', [$from, $to])
            ->get(['id', 'account_id', 'posted_on', 'amount_cents']);

        $byCents = [];
        foreach ($candidates as $c) {
            $byCents[(int) $c->amount_cents][] = $c;
        }

        $out = [];
        $taken = [];
        foreach ($lines as $line) {
            $day = MatchText::day(substr((string) $line->posted_on, 0, 10));
            $best = null;
            foreach ($byCents[-((int) $line->amount_cents)] ?? [] as $c) {
                $gap = abs($day - MatchText::day(substr((string) $c->posted_on, 0, 10)));
                if ($gap <= 3 && ! isset($taken[$c->id]) && ($best === null || $gap < $best[0])) {
                    $best = [$gap, $c];
                }
            }
            if ($best !== null) {
                $taken[$best[1]->id] = true;
                $out[(int) $line->id] = ['line_id' => (int) $best[1]->id, 'account_id' => (int) $best[1]->account_id, 'account_name' => (string) $others[$best[1]->account_id], 'date' => substr((string) $best[1]->posted_on, 0, 10)];
            }
        }

        return $out;
    }

    /**
     * Uncleared rows in a wide window (45 days either side) for the "close amount" and "timing" causes.
     *
     * @return array<string, mixed>
     */
    private function wideEntries(BankReconciliation $session, Account $account, Collection $lines): array
    {
        $from = date('Y-m-d', strtotime($lines->min(fn ($l) => substr((string) $l->posted_on, 0, 10)).' -45 days'));
        $to = date('Y-m-d', strtotime($lines->max(fn ($l) => substr((string) $l->posted_on, 0, 10)).' +45 days'));
        $signed = Transaction::signedCentsSql('transactions');

        $rows = Transaction::query()->forContext($session->ledgerContext())->counted()->where('account_id', $account->id)->whereNull('bank_match_id')->whereNull('reconciliation_id')
            ->whereBetween('date', [$from, $to])->whereRaw("({$signed}) <> 0")
            ->toBase()->selectRaw("transactions.id, transactions.date, transactions.description, ({$signed}) AS signed")->get();

        $byCents = [];
        $byDay = [];
        foreach ($rows as $row) {
            $entry = ['id' => (int) $row->id, 'date' => substr((string) $row->date, 0, 10), 'day' => MatchText::day(substr((string) $row->date, 0, 10)), 'signed' => (int) $row->signed, 'description' => (string) $row->description] + MatchText::prepare($row->description);
            $byCents[$entry['signed']][] = $entry;
            $byDay[$entry['day']][] = $entry;
        }

        return ['by_cents' => $byCents, 'by_day' => $byDay];
    }

    /** An entry with at least half the same words and nearly the same date but another amount. */
    private function closeAmount(array $line, array $wide): ?array
    {
        if ($line['tokens'] === []) {
            return null;
        }

        $best = null;
        $candidates = [];
        for ($d = $line['day'] - MatchingService::WINDOW_DAYS; $d <= $line['day'] + MatchingService::WINDOW_DAYS; $d++) {
            foreach ($wide['by_day'][$d] ?? [] as $entry) {
                $candidates[] = $entry;
            }
        }

        foreach ($candidates as $entry) {
            if ($entry['signed'] === $line['cents'] || ($entry['signed'] > 0) !== ($line['cents'] > 0)) {
                continue;
            }
            $common = array_intersect($line['tokens'], $entry['tokens']);
            $docMatch = array_intersect($line['docs'], $entry['docs']) !== [];
            if ($entry['tokens'] === [] || (! $docMatch && count($common) / max(1, min(count($line['tokens']), count($entry['tokens']))) < 0.5)) {
                continue;
            }
            if ($best === null || abs($entry['day'] - $line['day']) < abs($best['day'] - $line['day'])) {
                $best = $entry;
            }
        }

        return $best;
    }

    /** An entry with exactly this amount dated outside the match window. */
    private function timing(array $line, array $wide): ?array
    {
        $best = null;
        foreach ($wide['by_cents'][$line['cents']] ?? [] as $entry) {
            $gap = abs($entry['day'] - $line['day']);
            if ($gap > MatchingService::WINDOW_DAYS && ($best === null || $gap < abs($best['day'] - $line['day']))) {
                $best = $entry;
            }
        }

        return $best;
    }
}
