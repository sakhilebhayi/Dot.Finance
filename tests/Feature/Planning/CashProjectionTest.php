<?php

namespace Tests\Feature\Planning;

use App\Contracts\Planning\PayrollCommitmentsProvider;
use App\Contracts\Planning\TaxObligationsProvider;
use App\Contracts\Reporting\OpenItemsProvider;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LedgerSetting;
use App\Models\Planning\Scenario;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Services\Planning\Assumptions;
use App\Services\Planning\CashProjection;
use App\Services\Reporting\NullOpenItemsProvider;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;

/**
 * The projection is built only from things in the books or things a person said. Cash today in these books is
 * 54,075,000 (opening 500,000 + six months of sales less rent and fuel).
 */
class CashProjectionTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private const TODAY = '2026-07-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCompanyBooks();
    }

    private function project(array $assumptions = [], string $horizon = 'weeks_13', int $buffer = 0): array
    {
        return app(CashProjection::class)->project($this->ctx, LedgerSetting::query()->forContext($this->ctx)->first(), CarbonImmutable::parse(self::TODAY), $horizon, $assumptions, $buffer);
    }

    private function event(array $p, string $needle): array
    {
        foreach ($p['events'] as $e) {
            if (str_contains($e['label'], $needle)) {
                return $e;
            }
        }
        $this->fail("no event like {$needle} in ".json_encode(array_column($p['events'], 'label')));
    }

    /** A customer who has paid three invoices late (10, 10 and 12 days) and has one open. */
    private function slowPayer(): void
    {
        $a = $this->party('Slow Payer', 'customer', 30);
        foreach ([['2026-01-05', '2026-02-14'], ['2026-02-05', '2026-03-17'], ['2026-03-05', '2026-04-16']] as $i => [$issued, $paid]) {
            $this->invoice($issued, 100_000, $a, null, 'sp'.$i);
            $this->receipt($paid, 100_000, $a);
        }
        $this->invoice('2026-06-15', 500_000, $a, null, 'sp-open'); // due 15 Jul
    }

    public function test_starting_cash_is_the_cash_ledger_and_the_horizon_is_what_was_asked(): void
    {
        $p = $this->project();
        $this->assertSame(54_075_000, $p['opening_cents']);
        $this->assertSame('2026-09-29', $p['end'], '13 weeks: today and the 90 days after');
        $this->assertSame(54_075_000, $p['closing_cents'], 'nothing is due, so the projection is flat');
        $this->assertSame(['date' => self::TODAY, 'balance_cents' => 54_075_000], $p['lowest']);
        $this->assertNull($p['shortage']);
        $this->assertCount(13, $p['buckets']);
        $this->assertSame('2027-06-30', $this->project([], 'months_12')['end']);
        $this->assertCount(12, $this->project([], 'months_12')['buckets']);
    }

    public function test_an_invoice_is_expected_on_the_due_date_plus_the_customers_own_lateness_when_three_have_been_settled(): void
    {
        $this->slowPayer();
        $p = $this->project();
        $e = $this->event($p, 'sp-open');
        $this->assertSame('2026-07-25', $e['date'], 'due 15 Jul plus an average of 10 days late (10, 10 and 12 days over three invoices)');
        $this->assertSame(500_000, $e['cents']);
        $this->assertStringContainsString('has paid 3 invoices', $e['method']);
        $this->assertFalse($e['overdue']);
    }

    public function test_with_fewer_than_three_settled_invoices_it_uses_the_due_date_and_says_why(): void
    {
        $b = $this->party('New Customer', 'customer', 30);
        $this->invoice('2026-01-05', 100_000, $b, null, 'nc0');
        $this->receipt('2026-02-25', 100_000, $b);
        $this->invoice('2026-06-20', 250_000, $b, null, 'nc-open'); // due 20 Jul
        $e = $this->event($this->project(), 'nc-open');
        $this->assertSame('2026-07-20', $e['date']);
        $this->assertStringContainsString('fewer than 3 settled invoices', $e['method']);
    }

    public function test_late_items_are_moved_to_tomorrow_and_flagged_and_bills_go_out_on_their_due_date(): void
    {
        $c = $this->party('Late Customer', 'customer', 30);
        $this->invoice('2026-04-01', 80_000, $c, null, 'late-inv'); // due 1 May, long overdue
        $s = $this->party('Supplier', 'supplier', 30);
        $this->bill('2026-06-10', 300_000, $s, 'Rent', null, 'bill-a');   // due 10 Jul
        $this->bill('2026-05-01', 120_000, $s, 'Rent', null, 'bill-late'); // due 31 May

        $p = $this->project();
        $late = $this->event($p, 'late-inv');
        $this->assertSame(['2026-07-02', true, 80_000], [$late['date'], $late['overdue'], $late['cents']]);
        $this->assertStringContainsString('not guaranteed', $late['method']);

        $a = $this->event($p, 'bill-a');
        $this->assertSame(['2026-07-10', -300_000, false], [$a['date'], $a['cents'], $a['overdue']]);
        $this->assertSame(['2026-07-02', true], [$this->event($p, 'bill-late')['date'], $this->event($p, 'bill-late')['overdue']]);

        // Balance after each event is the running balance.
        $this->assertSame(54_075_000 + 80_000 - 120_000, $this->event($p, 'bill-late')['balance_cents'] ?? null, 'both 2 July items settle the same day: 54,075,000 + 80,000 - 120,000');
    }

    public function test_recurring_rules_are_projected_in_the_base_currency_and_ended_or_paused_ones_are_not(): void
    {
        RecurringRule::factory()->forCompany($this->company)->create(['account_id' => $this->bank->id, 'description' => 'Office lease', 'amount_cents' => 100_000, 'type' => 'expense', 'start_date' => '2026-07-05', 'next_occurrence_date' => '2026-07-05']);
        RecurringRule::factory()->forCompany($this->company)->create(['account_id' => $this->bank->id, 'description' => 'Retainer', 'amount_cents' => 40_000, 'type' => 'income', 'start_date' => '2026-07-10', 'next_occurrence_date' => '2026-07-10']);
        RecurringRule::factory()->forCompany($this->company)->create(['account_id' => $this->bank->id, 'description' => 'Paused thing', 'amount_cents' => 9_999, 'type' => 'expense', 'start_date' => '2026-07-07', 'next_occurrence_date' => '2026-07-07', 'paused_at' => now()]);

        $p = $this->project();
        $leases = array_filter($p['events'], fn ($e) => $e['label'] === 'Office lease');
        $this->assertCount(3, $leases, '5 Jul, 5 Aug and 5 Sep fall inside 13 weeks');
        $this->assertSame(54_075_000 - 3 * 100_000 + 3 * 40_000, $p['closing_cents']);
        $this->assertEmpty(array_filter($p['events'], fn ($e) => $e['label'] === 'Paused thing'));
    }

    public function test_the_lowest_balance_and_the_first_shortage_are_found_with_their_dates_and_amounts(): void
    {
        $s = $this->party('Big Supplier', 'supplier', 30);
        $this->bill('2026-06-20', 54_500_000, $s, 'Rent', null, 'big'); // more than the cash on hand
        $c = $this->party('Good Customer', 'customer', 50);
        $this->invoice('2026-07-01', 2_000_000, $c, null, 'rescue');   // arrives on 20 Aug

        $p = $this->project([], 'weeks_13', 0);
        $this->assertSame(['date' => '2026-07-20', 'balance_cents' => 54_075_000 - 54_500_000], $p['lowest']);
        $this->assertSame(['date' => '2026-07-20', 'balance_cents' => -425_000], $p['shortage']);
        $this->assertSame(54_075_000 - 54_500_000 + 2_000_000, $p['closing_cents']);

        // A buffer makes an earlier shortage: with 54,000,000 required, the bill is not needed to trip it.
        $buffered = $this->project([], 'weeks_13', 54_100_000);
        $this->assertSame(self::TODAY, $buffered['shortage']['date'], 'already below the buffer today');
    }

    public function test_tax_and_payroll_come_only_from_their_modules_and_the_projection_says_when_they_are_missing(): void
    {
        $p = $this->project();
        $this->assertStringContainsString('No tax payments are included', implode(' ', $p['notes']));
        $this->assertStringContainsString('No payroll is included', implode(' ', $p['notes']));
        $this->assertStringContainsString('NOT reflected', implode(' ', $p['notes']));

        $this->app->bind(TaxObligationsProvider::class, fn () => new class implements TaxObligationsProvider
        {
            public function obligations(LedgerContext $context, string $from, string $to): array
            {
                return [['date' => '2026-08-25', 'label' => 'VAT201 payment', 'amount_cents' => 1_200_000, 'kind' => 'estimate']];
            }
        });
        $this->app->bind(PayrollCommitmentsProvider::class, fn () => new class implements PayrollCommitmentsProvider
        {
            public function commitments(LedgerContext $context, string $from, string $to): array
            {
                return [['date' => '2026-07-31', 'label' => 'July salaries', 'amount_cents' => 3_000_000, 'kind' => 'calculated']];
            }
        });
        $q = $this->project();
        $tax = $this->event($q, 'VAT201');
        $this->assertSame([-1_200_000, true, 'tax'], [$tax['cents'], $tax['estimate'], $tax['source']], 'a tax payment is an estimate and labelled one');
        $this->assertFalse($this->event($q, 'July salaries')['estimate']);
        $this->assertSame(54_075_000 - 4_200_000, $q['closing_cents']);
        $this->assertStringNotContainsString('No tax payments', implode(' ', $q['notes']));
    }

    public function test_assumptions_are_applied_to_the_projection_and_never_to_the_books(): void
    {
        $this->slowPayer();
        $s = $this->party('Supplier', 'supplier', 30);
        $this->bill('2026-06-10', 300_000, $s, 'Rent', null, 'bill-a'); // due 10 Jul
        $journals = fn () => JournalEntry::query()->forContext($this->ctx)->count().'|'.JournalLine::query()->forContext($this->ctx)->sum('debit_cents');
        $fingerprint = fn () => md5(json_encode([$journals(), Transaction::query()->forContext($this->ctx)->count(), Scenario::query()->forContext($this->ctx)->count()]));
        $before = $fingerprint();

        $base = $this->project();
        $faster = $this->project(Assumptions::normalise([['type' => 'collect_faster', 'days' => 7]]));
        $this->assertSame('2026-07-18', $this->event($faster, 'sp-open')['date']);
        $this->assertStringContainsString('7 days sooner (assumption)', $this->event($faster, 'sp-open')['method']);

        $later = $this->project(Assumptions::normalise([['type' => 'delay_payment', 'days' => 14]]));
        $this->assertSame('2026-07-24', $this->event($later, 'bill-a')['date']);

        $hire = $this->project(Assumptions::normalise([['type' => 'new_cost', 'name' => 'New hire', 'amount' => '25000.00', 'start_on' => '2026-07-31', 'months' => '2']]));
        $this->assertCount(2, array_filter($hire['events'], fn ($e) => $e['label'] === 'New hire'));
        $this->assertSame($base['closing_cents'] - 2 * 2_500_000, $hire['closing_cents']);

        $oneOff = $this->project(Assumptions::normalise([['type' => 'one_off', 'name' => 'Equipment', 'direction' => 'out', 'amount' => '1000.00', 'on' => '2026-08-01']]));
        $this->assertSame($base['closing_cents'] - 100_000, $oneOff['closing_cents']);

        $this->assertSame($before, $fingerprint(), 'projecting under assumptions wrote nothing to the books');
        $this->assertTrue(collect($hire['events'])->where('source', 'assumption')->every(fn ($e) => $e['estimate']), 'assumption items are labelled estimates');
    }

    public function test_a_recurring_price_change_applies_to_recurring_income_only(): void
    {
        RecurringRule::factory()->forCompany($this->company)->create(['account_id' => $this->bank->id, 'description' => 'Retainer', 'amount_cents' => 100_000, 'type' => 'income', 'start_date' => '2026-07-10', 'next_occurrence_date' => '2026-07-10']);
        RecurringRule::factory()->forCompany($this->company)->create(['account_id' => $this->bank->id, 'description' => 'Lease', 'amount_cents' => 50_000, 'type' => 'expense', 'start_date' => '2026-07-10', 'next_occurrence_date' => '2026-07-10']);

        $p = $this->project(Assumptions::normalise([['type' => 'change_recurring', 'direction' => 'income', 'percent' => '5']]));
        $this->assertSame(105_000, $this->event($p, 'Retainer')['cents']);
        $this->assertSame(-50_000, $this->event($p, 'Lease')['cents']);
        $down = $this->project(Assumptions::normalise([['type' => 'change_recurring', 'direction' => 'expense', 'percent' => '-10']]));
        $this->assertSame(-45_000, $this->event($down, 'Lease')['cents']);
    }

    public function test_assumptions_are_validated_and_described_in_words(): void
    {
        foreach ([
            [['type' => 'collect_faster', 'days' => '0']], [['type' => 'collect_faster', 'days' => '91']], [['type' => 'collect_faster', 'days' => '1.5']],
            [['type' => 'new_cost', 'name' => 'x', 'amount' => '12e3', 'start_on' => '2026-07-31']], [['type' => 'new_cost', 'name' => '', 'amount' => '10', 'start_on' => '2026-07-31']],
            [['type' => 'one_off', 'name' => 'x', 'direction' => 'sideways', 'amount' => '1', 'on' => '2026-07-31']], [['type' => 'change_recurring', 'direction' => 'income', 'percent' => '-95']],
            [['type' => 'teleport']], [['type' => 'one_off', 'name' => 'x', 'direction' => 'in', 'amount' => '1', 'on' => '2026-02-30']],
        ] as $bad) {
            try {
                Assumptions::normalise($bad);
                $this->fail('expected a refusal for '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Assumptions::normalise(array_fill(0, 13, ['type' => 'collect_faster', 'days' => '5']));
    }

    public function test_assumption_descriptions_read_as_sentences(): void
    {
        $a = Assumptions::normalise([['type' => 'new_cost', 'name' => 'A hire', 'amount' => '25000.00', 'start_on' => '2026-08-01', 'months' => ''], ['type' => 'change_recurring', 'direction' => 'income', 'percent' => '-2.5']]);
        $this->assertSame('A hire: ZAR 25,000.00 a month from 2026-08-01 with no end.', Assumptions::describe($a[0], 'ZAR'));
        $this->assertSame('Recurring income changes by -2.50%.', Assumptions::describe($a[1]));
    }

    public function test_with_no_source_of_open_items_the_projection_still_works_and_includes_none(): void
    {
        $this->slowPayer();
        $this->app->bind(OpenItemsProvider::class, NullOpenItemsProvider::class);
        $p = $this->project();
        $this->assertSame([], $p['events']);
        $this->assertStringContainsString('No source of open invoices', $p['source']);
    }
}
