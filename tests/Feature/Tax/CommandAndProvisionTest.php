<?php

namespace Tests\Feature\Tax;

use App\Models\Accounting\GlAccount;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxReminderNotice;
use App\Services\Accounting\Chart;
use App\Services\Accounting\GlQuery;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\IncomeTaxEstimator;
use App\Services\Tax\IncomeTaxProvision;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

class CommandAndProvisionTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    public function test_the_daily_command_builds_each_ledgers_calendar_and_reminders_and_nothing_crosses(): void
    {
        $this->taxLedger();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 06:00'));
        $this->vatProfile();

        $other = $this->company($this->people['owner'], 'Other (Pty) Ltd');
        $otherCtx = $this->companyContext($other);
        $this->enableAccounting($otherCtx, 'ZAR');
        $this->vatProfile(['vat_registered' => false, 'employs_staff' => false, 'legal_entity' => 'sole_proprietor'], $otherCtx);

        $this->artisan('finance:tax:calendar')->assertSuccessful();

        $mine = TaxDeadline::query()->forContext($this->tax)->count();
        $theirs = TaxDeadline::query()->forContext($otherCtx)->count();
        $this->assertGreaterThan(5, $mine);
        $this->assertSame(0, TaxDeadline::query()->forContext($otherCtx)->whereIn('obligation', ['vat201', 'emp201'])->count());
        $this->assertGreaterThan(0, $theirs);
        $this->assertGreaterThan(0, TaxReminderNotice::query()->forContext($this->tax)->count());

        $this->artisan('finance:tax:calendar')->assertSuccessful();
        $this->assertSame($mine, TaxDeadline::query()->forContext($this->tax)->count(), 'idempotent');

        $this->artisan('finance:tax:calendar', ['--ledger' => 'company:'.$other->id])->assertSuccessful();
    }

    public function test_the_provision_is_a_suggestion_until_confirmed_and_never_doubles(): void
    {
        $this->taxLedger();
        $this->travelTo(CarbonImmutable::parse('2027-03-10 09:00'));
        $profile = $this->vatProfile(['legal_entity' => 'company', 'financial_year_end_month' => 2]);
        $this->sale('2026-06-15', 100_000_000, 'ZA_VAT_ZERO');
        $this->purchase('2026-07-01', 40_000_000, 'ZA_VAT_ZERO', 0, null, 'Rent');   // profit R600,000: tax 27% = R162,000

        $svc = app(IncomeTaxProvision::class);
        $estimate = fn () => LedgerContext::run($this->tax, fn () => app(IncomeTaxEstimator::class)->estimate($this->tax, $profile, CarbonImmutable::parse('2027-02-28'), CarbonImmutable::today()));
        $expense = GlAccount::query()->forContext($this->tax)->where('name', 'Bank charges')->firstOrFail();

        $s = LedgerContext::run($this->tax, fn () => $svc->suggest($this->tax, $estimate(), $expense));
        $this->assertSame(16_200_000, $s['amount_cents']);
        $this->assertSame('suggestion', $s['basis']);
        $this->assertSame(array_sum(array_column($s['lines'], 'debit_cents')), array_sum(array_column($s['lines'], 'credit_cents')));
        $net = fn () => LedgerContext::run($this->tax, fn () => app(GlQuery::class)->netThrough($this->tax, app(Chart::class)->system('income_tax_payable')));
        $this->assertSame(0, $net(), 'a suggestion posts nothing');

        $entry = $svc->post($this->tax, $estimate(), $expense, $this->people['owner']);
        $this->assertSame(-16_200_000, $net());
        $this->assertSame('adjustment', $entry->source_type);

        $again = LedgerContext::run($this->tax, fn () => $svc->suggest($this->tax, $estimate(), $expense));
        $this->assertSame(0, $again['amount_cents'], 'confirming twice does not double the provision');
        $this->expectException(TaxException::class);
        $svc->post($this->tax, $estimate(), $expense, $this->people['owner']);
    }

    public function test_the_provision_refuses_a_non_expense_account(): void
    {
        $this->taxLedger();
        $this->travelTo(CarbonImmutable::parse('2027-03-10 09:00'));
        $profile = $this->vatProfile(['legal_entity' => 'company', 'financial_year_end_month' => 2]);
        $this->sale('2026-06-15', 100_000_000, 'ZA_VAT_ZERO');
        $est = LedgerContext::run($this->tax, fn () => app(IncomeTaxEstimator::class)->estimate($this->tax, $profile, CarbonImmutable::parse('2027-02-28'), CarbonImmutable::today()));
        $bank = GlAccount::query()->forContext($this->tax)->where('class', 'asset')->firstOrFail();

        $this->expectException(TaxException::class);
        app(IncomeTaxProvision::class)->post($this->tax, $est, $bank, $this->people['owner']);
    }
}
