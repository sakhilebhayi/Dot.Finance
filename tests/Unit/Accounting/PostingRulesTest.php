<?php

namespace Tests\Unit\Accounting;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\Accounting\Exceptions\MissingFxRate;
use App\Services\Accounting\FxRateProvider;
use App\Services\Accounting\ImpliedFxRateProvider;
use App\Services\Accounting\PostingMap;
use App\Services\Accounting\PostingRequest;
use App\Services\Accounting\PostingRules;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** The posting rules touch no database: rows and a hand-built map go in, balanced lines come out. */
class PostingRulesTest extends TestCase
{
    private const BANK = 10;      // GL id of cash account 1 (ZAR)

    private const SAVINGS = 11;   // GL id of cash account 2 (ZAR)

    private const USD = 12;       // GL id of cash account 3 (USD)

    private const EUR = 13;       // GL id of cash account 4 (EUR)

    private const CARD = 14;      // liability GL id of cash account 5 (credit card, ZAR)

    private function map(string $base = 'ZAR'): PostingMap
    {
        return new PostingMap($base, [
            'opening_balance_equity' => 90, 'uncategorised_income' => 91, 'uncategorised_expense' => 92, 'suspense' => 93, 'fx_realised' => 94, 'rounding' => 95,
        ], [
            1 => ['gl' => self::BANK, 'currency' => 'ZAR', 'type' => 'checking'],
            2 => ['gl' => self::SAVINGS, 'currency' => 'ZAR', 'type' => 'savings'],
            3 => ['gl' => self::USD, 'currency' => 'USD', 'type' => 'checking'],
            4 => ['gl' => self::EUR, 'currency' => 'EUR', 'type' => 'checking'],
            5 => ['gl' => self::CARD, 'currency' => 'ZAR', 'type' => 'credit'],
        ], [7 => 70, 8 => 80]);
    }

    private function tx(array $attributes): Transaction
    {
        $t = new Transaction;
        $t->forceFill($attributes + ['id' => 1, 'type' => 'expense', 'amount_cents' => 1_000, 'date' => '2026-03-01', 'account_id' => 1, 'description' => 'Test']);

        return $t;
    }

    private function rules(?FxRateProvider $fx = null): PostingRules
    {
        return new PostingRules($fx ?? new ImpliedFxRateProvider);
    }

    private function ctx(): LedgerContext
    {
        return LedgerContext::personal(1);
    }

    /** @return array<int, array{0: int, 1: int}> account => [debit, credit] */
    private function byAccount(PostingRequest $r): array
    {
        $out = [];
        foreach ($r->lines as $l) {
            $out[$l->account] = [($out[$l->account][0] ?? 0) + $l->debitCents, ($out[$l->account][1] ?? 0) + $l->creditCents];
        }

        return $out;
    }

    public function test_an_expense_debits_the_category_and_credits_the_cash_account(): void
    {
        $r = $this->rules()->transaction($this->ctx(), $this->tx(['category_id' => 7]), $this->map());

        $this->assertSame([70 => [1_000, 0], self::BANK => [0, 1_000]], $this->byAccount($r));
        $this->assertSame($r->totalDebits(), $r->totalCredits());
        $this->assertSame('transaction', $r->sourceType);
        $this->assertSame('2026-03-01', $r->entryDate->toDateString());
        $this->assertSame(1, $r->lines[1]->cashAccountId);
        $this->assertSame(7, $r->lines[0]->categoryId);
    }

    public function test_an_income_debits_the_cash_account_and_credits_the_category(): void
    {
        $r = $this->rules()->transaction($this->ctx(), $this->tx(['type' => 'income', 'category_id' => 8, 'amount_cents' => 2_500]), $this->map());

        $this->assertSame([self::BANK => [2_500, 0], 80 => [0, 2_500]], $this->byAccount($r));
    }

    public function test_a_row_with_no_category_uses_the_uncategorised_accounts(): void
    {
        $expense = $this->rules()->transaction($this->ctx(), $this->tx([]), $this->map());
        $income = $this->rules()->transaction($this->ctx(), $this->tx(['type' => 'income']), $this->map());

        $this->assertArrayHasKey(92, $this->byAccount($expense));
        $this->assertArrayHasKey(91, $this->byAccount($income));
    }

    public function test_a_credit_card_expense_credits_the_liability_so_the_cash_balance_stays_negative(): void
    {
        $r = $this->rules()->transaction($this->ctx(), $this->tx(['account_id' => 5, 'category_id' => 7]), $this->map());

        // Cash balance change is -1000; the GL net on the card account (debit - credit) is also -1000.
        $this->assertSame([0, 1_000], $this->byAccount($r)[self::CARD]);
    }

    public function test_transfer_type_and_zero_amounts_have_no_single_row_posting(): void
    {
        $this->assertNull($this->rules()->transaction($this->ctx(), $this->tx(['type' => 'transfer']), $this->map()));
        $this->assertNull($this->rules()->transaction($this->ctx(), $this->tx(['amount_cents' => 0]), $this->map()));
    }

    public function test_opening_balances_post_against_opening_balance_equity_on_the_right_side(): void
    {
        $rules = $this->rules();
        $positive = new Account;
        $positive->forceFill(['id' => 1, 'name' => 'Main', 'opening_balance_cents' => 50_000]);
        $negative = new Account;
        $negative->forceFill(['id' => 5, 'name' => 'Card', 'opening_balance_cents' => -12_000]);
        $zero = new Account;
        $zero->forceFill(['id' => 2, 'name' => 'Empty', 'opening_balance_cents' => 0]);
        $date = CarbonImmutable::parse('2026-01-01');

        $this->assertSame([self::BANK => [50_000, 0], 90 => [0, 50_000]], $this->byAccount($rules->openingBalance($this->ctx(), $positive, $date, $this->map())));
        $this->assertSame([self::CARD => [0, 12_000], 90 => [12_000, 0]], $this->byAccount($rules->openingBalance($this->ctx(), $negative, $date, $this->map())));
        $this->assertNull($rules->openingBalance($this->ctx(), $zero, $date, $this->map()));
    }

    public function test_a_same_currency_transfer_is_one_entry_for_the_pair(): void
    {
        $out = $this->tx(['id' => 1, 'type' => 'transfer', 'transfer_direction' => 'out', 'transfer_group' => 'g', 'amount_cents' => 4_000]);
        $in = $this->tx(['id' => 2, 'type' => 'transfer', 'transfer_direction' => 'in', 'transfer_group' => 'g', 'amount_cents' => 4_000, 'account_id' => 2]);

        $r = $this->rules()->transfer($this->ctx(), $out, $in, 'g', $this->map());

        $this->assertSame('transfer', $r->sourceType);
        $this->assertSame('g', $r->sourceId);
        $this->assertSame([self::SAVINGS => [4_000, 0], self::BANK => [0, 4_000]], $this->byAccount($r));
        $this->assertCount(2, $r->lines);
    }

    public function test_a_cross_currency_transfer_takes_its_value_from_the_base_leg_and_stores_the_implied_rate(): void
    {
        // 100,000 ZAR cents out, 5,000 USD cents in: 20.00 ZAR per USD.
        $out = $this->tx(['type' => 'transfer', 'transfer_direction' => 'out', 'transfer_group' => 'g', 'amount_cents' => 100_000]);
        $in = $this->tx(['id' => 2, 'type' => 'transfer', 'transfer_direction' => 'in', 'transfer_group' => 'g', 'amount_cents' => 5_000, 'account_id' => 3]);

        $r = $this->rules()->transfer($this->ctx(), $out, $in, 'g', $this->map());

        $this->assertSame([self::USD => [100_000, 0], self::BANK => [0, 100_000]], $this->byAccount($r));
        $usdLine = $r->lines[0];
        $this->assertSame('USD', $usdLine->txnCurrency);
        $this->assertSame(5_000, $usdLine->txnAmountCents);
        $this->assertSame(2_000_000_000, $usdLine->fxRateE8, 'rate 20.00000000 stored as a scaled integer');
        $this->assertNull($r->lines[1]->txnCurrency, 'the base-currency leg carries no original currency');
        $this->assertCount(2, $r->lines, 'no FX difference line is needed when one leg is the base currency');
    }

    public function test_the_implied_rate_is_rounded_half_up_and_never_a_float(): void
    {
        $out = $this->tx(['type' => 'transfer', 'transfer_direction' => 'out', 'transfer_group' => 'g', 'amount_cents' => 100_000]);
        $in = $this->tx(['id' => 2, 'type' => 'transfer', 'transfer_direction' => 'in', 'transfer_group' => 'g', 'amount_cents' => 5_400, 'account_id' => 3]);

        $r = $this->rules()->transfer($this->ctx(), $out, $in, 'g', $this->map());

        // 100000 / 5400 = 18.518518518...  => 1851851852 (e8, rounded)
        $this->assertSame(1_851_851_852, $r->lines[0]->fxRateE8);
        $this->assertIsInt($r->lines[0]->fxRateE8);
    }

    public function test_a_transfer_between_two_foreign_currencies_needs_rates_and_books_the_difference(): void
    {
        $fx = new class implements FxRateProvider
        {
            public function rate(LedgerContext $context, string $from, string $to, CarbonImmutable $on): ?int
            {
                return ['USD' => 1_800_000_000, 'EUR' => 2_000_000_000][$from] ?? null; // 18.00 and 20.00 ZAR
            }
        };

        $out = $this->tx(['type' => 'transfer', 'transfer_direction' => 'out', 'transfer_group' => 'g', 'amount_cents' => 10_000, 'account_id' => 3]); // 100.00 USD = 1800.00 ZAR
        $in = $this->tx(['id' => 2, 'type' => 'transfer', 'transfer_direction' => 'in', 'transfer_group' => 'g', 'amount_cents' => 8_900, 'account_id' => 4]);   // 89.00 EUR = 1780.00 ZAR

        $r = $this->rules($fx)->transfer($this->ctx(), $out, $in, 'g', $this->map());
        $by = $this->byAccount($r);

        $this->assertSame([178_000, 0], $by[self::EUR]);
        $this->assertSame([0, 180_000], $by[self::USD]);
        $this->assertSame([2_000, 0], $by[94], '20.00 ZAR lost in conversion goes to Realised FX');
        $this->assertSame($r->totalDebits(), $r->totalCredits());
    }

    public function test_a_sub_cent_conversion_difference_goes_to_rounding_not_to_fx(): void
    {
        $fx = new class implements FxRateProvider
        {
            public function rate(LedgerContext $context, string $from, string $to, CarbonImmutable $on): ?int
            {
                return 1_800_000_000;
            }
        };
        // 3 USD cents x 18 = 54 ZAR cents out; 2 USD... use different legs so that the base values differ by 1.
        $out = $this->tx(['type' => 'transfer', 'transfer_direction' => 'out', 'transfer_group' => 'g', 'amount_cents' => 1_000, 'account_id' => 3]);  // 18,000
        $in = $this->tx(['id' => 2, 'type' => 'transfer', 'transfer_direction' => 'in', 'transfer_group' => 'g', 'amount_cents' => 1_000, 'account_id' => 3]);  // 18,000 -> equal, no diff

        $r = $this->rules($fx)->transfer($this->ctx(), $out, $in, 'g', $this->map());
        $this->assertCount(2, $r->lines);

        // A one-cent gap (e.g. legs differing by a rounding step): same currency, base values 18,001 vs 18,000.
        $fx2 = new class implements FxRateProvider
        {
            public function rate(LedgerContext $context, string $from, string $to, CarbonImmutable $on): ?int
            {
                return $from === 'USD' ? 1_800_000_000 : 1_800_100_000; // EUR a hair higher
            }
        };
        $in2 = $this->tx(['id' => 2, 'type' => 'transfer', 'transfer_direction' => 'in', 'transfer_group' => 'g', 'amount_cents' => 1_000, 'account_id' => 4]); // 18,001
        $r2 = $this->rules($fx2)->transfer($this->ctx(), $out, $in2, 'g', $this->map());
        $by = $this->byAccount($r2);

        $this->assertSame([0, 1], $by[95], 'the one-cent gain is booked to Rounding');
        $this->assertArrayNotHasKey(94, $by);
    }

    public function test_a_lone_transfer_leg_is_booked_against_suspense(): void
    {
        $in = $this->tx(['type' => 'transfer', 'transfer_direction' => 'in', 'transfer_group' => 'g', 'amount_cents' => 700, 'account_id' => 2]);
        $out = $this->tx(['type' => 'transfer', 'transfer_direction' => 'out', 'transfer_group' => 'g', 'amount_cents' => 900]);

        $this->assertSame([self::SAVINGS => [700, 0], 93 => [0, 700]], $this->byAccount($this->rules()->transfer($this->ctx(), null, $in, 'g', $this->map())));
        $this->assertSame([93 => [900, 0], self::BANK => [0, 900]], $this->byAccount($this->rules()->transfer($this->ctx(), $out, null, 'g', $this->map())));
        $this->assertNull($this->rules()->transfer($this->ctx(), null, null, 'g', $this->map()));
    }

    public function test_a_foreign_currency_row_needs_a_rate_and_carries_the_original_amount(): void
    {
        $this->expectException(MissingFxRate::class);
        $this->rules()->transaction($this->ctx(), $this->tx(['account_id' => 3]), $this->map());
    }

    public function test_a_foreign_currency_row_is_converted_with_integer_arithmetic(): void
    {
        $fx = new class implements FxRateProvider
        {
            public function rate(LedgerContext $context, string $from, string $to, CarbonImmutable $on): ?int
            {
                return 1_823_456_789; // 18.23456789
            }
        };

        $r = $this->rules($fx)->transaction($this->ctx(), $this->tx(['account_id' => 3, 'amount_cents' => 12_345]), $this->map());

        // 12,345 x 18.23456789 = 225,105.74... -> 225,106 cents
        $this->assertSame(225_106, $r->lines[0]->debitCents + $r->lines[0]->creditCents);
        $this->assertSame('USD', $r->lines[1]->txnCurrency);
        $this->assertSame(12_345, $r->lines[1]->txnAmountCents);
        $this->assertSame(1_823_456_789, $r->lines[1]->fxRateE8);
    }

    public function test_skip_reasons_name_the_rows_that_do_not_post(): void
    {
        $opening = CarbonImmutable::parse('2026-03-01');

        $this->assertNull(PostingRules::skipReason($this->tx(['date' => '2026-03-01']), $opening));
        $this->assertNull(PostingRules::skipReason($this->tx(['date' => '2020-01-01']), null));
        $this->assertStringContainsString('before the account', PostingRules::skipReason($this->tx(['date' => '2026-02-28']), $opening));
        $this->assertStringContainsString('Legacy transfer', PostingRules::skipReason($this->tx(['type' => 'transfer', 'transfer_group' => null]), null));
        $this->assertStringContainsString('zero', PostingRules::skipReason($this->tx(['amount_cents' => 0]), null));
    }

    public function test_the_signature_ignores_memo_and_line_order_but_not_amounts(): void
    {
        $a = $this->rules()->transaction($this->ctx(), $this->tx(['description' => 'One', 'category_id' => 7]), $this->map());
        $b = $this->rules()->transaction($this->ctx(), $this->tx(['description' => 'Completely different words', 'category_id' => 7]), $this->map());
        $c = $this->rules()->transaction($this->ctx(), $this->tx(['amount_cents' => 1_001, 'category_id' => 7]), $this->map());

        $this->assertSame($a->signature(), $b->signature());
        $this->assertNotSame($a->signature(), $c->signature());
    }
}
