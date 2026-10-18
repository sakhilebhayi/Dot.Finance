<?php

namespace Tests\Feature\Company;

use App\Models\Account;
use App\Models\Insight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * Weekly summaries are written per ledger, and a company's summary talks about the
 * company, not about "you".
 */
class CompanyInsightWordingTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runwayCase(Account $account, int $opening, int $spend): void
    {
        $account->forceFill(['opening_balance_cents' => $opening])->save();
        $this->entryOn($account, ['type' => 'expense', 'amount_cents' => $spend, 'date' => now()->subDay()->toDateString()]);
    }

    public function test_a_company_warning_names_the_company_and_never_says_you_or_your(): void
    {
        Carbon::setTestNow('2026-09-15');
        $company = $this->company($this->user(), 'Acme Mining');
        $this->runwayCase($this->companyAccount($company, ['currency' => 'ZAR']), 50_000, 40_000);

        $this->artisan('finance:generate-insights');

        $text = Insight::withoutGlobalScopes()->where('team_id', $company->id)->sole()->insight;
        $this->assertStringContainsString('Acme Mining', $text);
        $this->assertDoesNotMatchRegularExpression('/\b[Yy]our\b|\b[Yy]ou\b/', $text);
        $this->assertStringContainsString('run out', $text);
    }

    public function test_a_healthy_company_summary_names_the_company(): void
    {
        Carbon::setTestNow('2026-09-15');
        $company = $this->company($this->user(), 'Acme Mining');
        $this->runwayCase($this->companyAccount($company, ['currency' => 'ZAR']), 99_000_000, 1_000);

        $this->artisan('finance:generate-insights');

        $text = Insight::withoutGlobalScopes()->where('team_id', $company->id)->sole()->insight;
        $this->assertStringContainsString('Acme Mining', $text);
        $this->assertStringContainsString('comfortable runway', $text);
        $this->assertDoesNotMatchRegularExpression('/\b[Yy]our\b|\b[Yy]ou\b/', $text);
    }

    public function test_a_personal_summary_is_worded_exactly_as_before(): void
    {
        Carbon::setTestNow('2026-09-15');
        $user = $this->user();
        $this->runwayCase(Account::factory()->create(['user_id' => $user->id, 'currency' => 'ZAR']), 50_000, 40_000);

        $this->artisan('finance:generate-insights');

        $text = Insight::withoutGlobalScopes()->where('user_id', $user->id)->sole()->insight;
        $this->assertStringStartsWith('At your current ZAR spending pace of', $text);
        $this->assertStringContainsString('your liquid reserves of', $text);
    }

    public function test_the_summary_pages_are_worded_for_the_ledger_they_show(): void
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Acme Mining');

        $this->inCompany($owner, $company)->get(route('insights.index'))->assertOk()->assertSee('on the company\'s ledger', false)->assertDontSee('on your own ledger');
        $this->inPersonal($owner)->get(route('insights.index'))->assertOk()->assertSee('on your own ledger');
    }

    public function test_the_summary_for_each_ledger_is_unique_per_iso_week_independently(): void
    {
        Carbon::setTestNow('2026-09-15');
        $user = $this->user();
        $company = $this->company($user);
        $this->runwayCase(Account::factory()->create(['user_id' => $user->id]), 50_000, 40_000);
        $this->runwayCase($this->companyAccount($company), 50_000, 40_000);

        $this->artisan('finance:generate-insights');
        Carbon::setTestNow('2026-09-16');
        $this->artisan('finance:generate-insights');
        Carbon::setTestNow('2026-09-22');
        $this->artisan('finance:generate-insights');

        $this->assertSame(['2026-W38', '2026-W39'], Insight::withoutGlobalScopes()->where('team_id', $company->id)->orderBy('iso_week')->pluck('iso_week')->all());
        $this->assertSame(['2026-W38', '2026-W39'], Insight::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('iso_week')->pluck('iso_week')->all());
    }
}
