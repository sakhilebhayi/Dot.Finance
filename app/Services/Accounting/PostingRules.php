<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\Accounting\Exceptions\MissingFxRate;
use App\Services\Accounting\Exceptions\UnpostableSource;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * How a cash-ledger row becomes journal lines. Nothing here reads or writes the database: a row, the
 * ledger's PostingMap and a rate provider go in, a balanced PostingRequest (or null: "this row has no
 * accounting effect") comes out, so the rules are testable without one. The sign logic of the whole
 * accounting layer lives in this class and nowhere else.
 *
 *   income  T in cash account A, category C     Dr GL(A)        Cr GL(C)  (uncategorised income if none)
 *   expense                                     Dr GL(C)        Cr GL(A)  (uncategorised expense if none)
 *   transfer, both legs                         Dr GL(in)       Cr GL(out) (one entry for the pair)
 *   transfer, one leg left (peer deleted)       the leg against the suspense account
 *   opening balance of A                        Dr GL(A) / Cr Opening balance equity (reversed if negative)
 *
 * Money is posted in the ledger's base currency. A movement in another currency carries its original
 * currency, amount and rate on the line. A cross-currency transfer takes its value from whichever leg is
 * in the base currency (the rate is implied by the two amounts the person typed and stored); a transfer
 * between two foreign currencies converts each leg at the provider's rate, and any difference is posted to
 * Rounding (one cent or less) or Realised FX (more), so an entry always balances.
 */
final class PostingRules
{
    /** Bump when a rule changes meaning; stored on every entry. */
    public const VERSION = 1;

    public function __construct(private readonly FxRateProvider $fx) {}

    /** Why a cash-ledger row is not posted, or null when it is. */
    public static function skipReason(Transaction $t, ?CarbonImmutable $accountOpeningDate): ?string
    {
        if ($t->type === 'transfer' && $t->transfer_group === null) {
            return 'Legacy transfer row with no destination: it has never moved a balance.';
        }

        if ((int) $t->amount_cents <= 0) {
            return 'The amount is zero or not yet migrated to cents.';
        }

        if ($accountOpeningDate !== null && CarbonImmutable::parse($t->date->toDateString())->lt($accountOpeningDate)) {
            return 'Dated before the account\'s opening date: history the opening balance already absorbed.';
        }

        return null;
    }

    /** An income or expense row. Returns null for a row without accounting effect. */
    public function transaction(LedgerContext $context, Transaction $t, PostingMap $map): ?PostingRequest
    {
        if (! in_array($t->type, ['income', 'expense'], true) || (int) $t->amount_cents <= 0) {
            return null;
        }

        $cash = $map->cashAccount((int) $t->account_id);
        $date = CarbonImmutable::parse($t->date->toDateString());
        $money = $this->convert($context, (int) $t->amount_cents, $cash['currency'], $date, $map->baseCurrency);
        $counter = $map->categoryOrDefault($t->category_id !== null ? (int) $t->category_id : null, $t->type);
        $description = $t->description !== null ? mb_substr($t->description, 0, 255) : null;

        $cashLine = fn (bool $debit) => new PostingLine(
            $cash['gl'], $debit ? $money['base'] : 0, $debit ? 0 : $money['base'], $money['currency'], $money['amount'], $money['rate'],
            cashAccountId: (int) $t->account_id, description: $description,
        );
        $counterLine = fn (bool $debit) => new PostingLine(
            $counter, $debit ? $money['base'] : 0, $debit ? 0 : $money['base'], $money['currency'], $money['amount'], $money['rate'],
            categoryId: $t->category_id !== null ? (int) $t->category_id : null, description: $description,
        );

        $income = $t->type === 'income';

        return new PostingRequest(
            $date, 'transaction', $t->id, 1,
            ($income ? 'Income' : 'Expense').($description !== null ? ': '.$description : ''),
            $income ? [$cashLine(true), $counterLine(false)] : [$counterLine(true), $cashLine(false)],
        );
    }

    /**
     * A transfer: both legs, or the one leg that remains. $group is the shared transfer_group.
     * Returns null when neither leg counts.
     */
    public function transfer(LedgerContext $context, ?Transaction $out, ?Transaction $in, string $group, PostingMap $map): ?PostingRequest
    {
        $live = array_values(array_filter([$out, $in]));
        if ($live === []) {
            return null;
        }

        $date = CarbonImmutable::parse($live[0]->date->toDateString());
        $base = $map->baseCurrency;
        $description = $live[0]->description !== null ? mb_substr($live[0]->description, 0, 255) : null;
        $memo = 'Transfer'.($description !== null ? ': '.$description : '');

        if ($out === null || $in === null) {
            $leg = $out ?? $in;
            $cash = $map->cashAccount((int) $leg->account_id);
            $m = $this->convert($context, (int) $leg->amount_cents, $cash['currency'], $date, $base);
            $suspense = $map->system('suspense');
            $cashLine = fn (bool $debit) => new PostingLine($cash['gl'], $debit ? $m['base'] : 0, $debit ? 0 : $m['base'], $m['currency'], $m['amount'], $m['rate'], cashAccountId: (int) $leg->account_id, description: $description);
            $suspenseLine = fn (bool $debit) => new PostingLine($suspense, $debit ? $m['base'] : 0, $debit ? 0 : $m['base'], $m['currency'], $m['amount'], $m['rate'], description: 'Other leg of the transfer no longer exists');

            return new PostingRequest(
                $date, 'transfer', $group, 1, $memo.' (one leg only)',
                $leg->transfer_direction === 'in' ? [$cashLine(true), $suspenseLine(false)] : [$suspenseLine(true), $cashLine(false)],
            );
        }

        $outCash = $map->cashAccount((int) $out->account_id);
        $inCash = $map->cashAccount((int) $in->account_id);
        $outCents = (int) $out->amount_cents;
        $inCents = (int) $in->amount_cents;

        $outMoney = ['base' => 0, 'currency' => null, 'amount' => null, 'rate' => null];
        $inMoney = $outMoney;

        if ($outCash['currency'] === $base && $inCash['currency'] === $base) {
            $outMoney['base'] = $outCents;
            $inMoney['base'] = $inCents;
        } elseif ($outCash['currency'] === $base) {
            // The base-currency leg is the truth; the rate is implied by the two typed amounts and stored.
            $outMoney['base'] = $outCents;
            $inMoney = ['base' => $outCents, 'currency' => $inCash['currency'], 'amount' => $inCents, 'rate' => Money::ratioE8($outCents, $inCents)];
        } elseif ($inCash['currency'] === $base) {
            $inMoney['base'] = $inCents;
            $outMoney = ['base' => $inCents, 'currency' => $outCash['currency'], 'amount' => $outCents, 'rate' => Money::ratioE8($inCents, $outCents)];
        } else {
            $outMoney = $this->convert($context, $outCents, $outCash['currency'], $date, $base);
            $inMoney = $this->convert($context, $inCents, $inCash['currency'], $date, $base);
        }

        $debits = new PostingLine($inCash['gl'], $inMoney['base'], 0, $inMoney['currency'], $inMoney['amount'], $inMoney['rate'], cashAccountId: (int) $in->account_id, description: $description);
        $credits = new PostingLine($outCash['gl'], 0, $outMoney['base'], $outMoney['currency'], $outMoney['amount'], $outMoney['rate'], cashAccountId: (int) $out->account_id, description: $description);
        $lines = [$debits, $credits];

        $difference = $outMoney['base'] - $inMoney['base']; // positive: more left than arrived (a loss)
        if ($difference !== 0) {
            $key = abs($difference) <= 1 ? 'rounding' : 'fx_realised';
            $lines[] = $difference > 0
                ? new PostingLine($map->system($key), $difference, description: 'Conversion difference')
                : new PostingLine($map->system($key), 0, -$difference, description: 'Conversion difference');
        }

        return new PostingRequest($date, 'transfer', $group, 1, $memo, $lines);
    }

    /** The opening balance of a cash account as of $date. Null when it is zero. */
    public function openingBalance(LedgerContext $context, Account $account, CarbonImmutable $date, PostingMap $map): ?PostingRequest
    {
        $cents = (int) $account->opening_balance_cents;
        if ($cents === 0) {
            return null;
        }

        $cash = $map->cashAccount((int) $account->id);
        $money = $this->convert($context, abs($cents), $cash['currency'], $date, $map->baseCurrency);
        $debit = $cents > 0;

        $cashLine = new PostingLine($cash['gl'], $debit ? $money['base'] : 0, $debit ? 0 : $money['base'], $money['currency'], $money['amount'], $money['rate'], cashAccountId: (int) $account->id, description: 'Opening balance');
        $equity = new PostingLine($map->system('opening_balance_equity'), $debit ? 0 : $money['base'], $debit ? $money['base'] : 0, description: 'Opening balance');

        return new PostingRequest($date, 'opening_balance', $account->id, 1, 'Opening balance: '.$account->name, [$cashLine, $equity]);
    }

    /**
     * An amount in $currency as base-currency cents, plus the original-currency facts to store on the line.
     *
     * @return array{base: int, currency: ?string, amount: ?int, rate: ?int}
     */
    private function convert(LedgerContext $context, int $cents, string $currency, CarbonImmutable $on, string $base): array
    {
        if ($cents <= 0) {
            throw new InvalidArgumentException('Only positive amounts are converted.');
        }

        if ($currency === $base) {
            return ['base' => $cents, 'currency' => null, 'amount' => null, 'rate' => null];
        }

        $rate = $this->fx->rate($context, $currency, $base, $on)
            ?? throw new MissingFxRate("No {$currency} to {$base} exchange rate for {$on->toDateString()}.");

        $baseCents = Money::mulDivRound($cents, $rate, FxRateProvider::ONE);

        if ($baseCents <= 0) {
            throw new UnpostableSource("{$cents} {$currency} cents rounds to nothing in {$base}.");
        }

        return ['base' => $baseCents, 'currency' => $currency, 'amount' => $cents, 'rate' => $rate];
    }
}
