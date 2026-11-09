<?php

namespace Tests\Feature\Intelligence;

use App\Models\Budget;
use App\Models\Intelligence\AssistantMessage;
use App\Models\RecurringRule;
use App\Models\Shared\Party;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\FinanceAssistant;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsIntelFixtures;
use Tests\TestCase;

class AssistantAnswersTest extends TestCase
{
    use BuildsAccounting;
    use BuildsIntelFixtures;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixTime();
        $this->b = $this->business();
    }

    private function ask(string $question, $user = null, ?LedgerContext $context = null): AssistantMessage
    {
        return app(FinanceAssistant::class)->ask($user ?? $this->b['owner'], $context ?? $this->b['context'], $question);
    }

    public function test_spend_this_month_is_summed_from_the_ledger_with_a_comparison_and_sources(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 40000, '2026-11-03');
        $this->spend($this->b['bank'], $this->b['fuel'], 60000, '2026-11-10');
        $this->spend($this->b['bank'], $this->b['food'], 50000, '2026-10-12'); // October, for the comparison
        $this->spend($this->b['bank'], $this->b['food'], 99999, '2026-11-30', 'future, outside "so far"');

        $m = $this->ask('How much did I spend this month?');

        $this->assertSame('answered', $m->status);
        $this->assertSame('calculated', $m->basis);
        $this->assertStringContainsString('ZAR 1,000.00', $m->answer_text);
        $this->assertStringNotContainsString('1,999', $m->answer_text);
        $facts = collect($m->answer['facts'])->pluck('value', 'label');
        $this->assertStringContainsString('up ZAR 500.00', $facts->first(fn ($v, $k) => str_starts_with($k, 'Compared with')));
        $this->assertNotEmpty($m->answer['sources']);
        $this->assertSame(['Category', 'Spent'], $m->answer['columns']);
        $this->assertSame('Fuel', $m->answer['rows'][0]['cells'][0]);
    }

    public function test_transfers_and_income_are_not_spending(): void
    {
        $this->spend($this->b['bank'], $this->b['sales'], 900000, '2026-11-04', 'Sale', 'income');
        $this->spend($this->b['bank'], $this->b['food'], 10000, '2026-11-05');

        $this->assertStringContainsString('ZAR 100.00', $this->ask('how much did I spend this month')->answer_text);
    }

    public function test_no_expenses_says_so_instead_of_inventing_a_zero(): void
    {
        $m = $this->ask('How much did I spend this month?');

        $this->assertSame('no_data', $m->status);
        $this->assertStringContainsString("I don't have any expenses", $m->answer_text);
        $this->assertStringNotContainsString('ZAR 0.00', $m->answer_text);
    }

    public function test_which_expenses_increased_ranks_categories_by_the_rise(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 10000, '2026-10-05');
        $this->spend($this->b['bank'], $this->b['food'], 30000, '2026-11-05');
        $this->spend($this->b['bank'], $this->b['fuel'], 10000, '2026-10-05');
        $this->spend($this->b['bank'], $this->b['fuel'], 80000, '2026-11-06');

        $m = $this->ask('Which expenses increased the most?');

        $this->assertSame('answered', $m->status);
        $this->assertStringContainsString('Fuel', $m->answer_text);
        $this->assertStringContainsString('ZAR 700.00', $m->answer_text);
        $this->assertStringContainsString('700%', $m->answer_text);
        $this->assertSame('Fuel', $m->answer['rows'][0]['cells'][0]);
        $this->assertSame('Food', $m->answer['rows'][1]['cells'][0]);
    }

    public function test_cash_position_is_the_derived_balance_per_account(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 250000, '2026-11-03');

        $m = $this->ask('What is my cash position?');

        $this->assertStringContainsString('ZAR 7,500.00', $m->answer_text); // 10,000.00 opening less 2,500.00
        $this->assertSame('Cheque', $m->answer['rows'][0]['cells'][0]);
    }

    public function test_profit_is_money_in_less_money_out_and_says_it_is_a_cash_view(): void
    {
        $this->spend($this->b['bank'], $this->b['sales'], 500000, '2026-11-02', 'Sale', 'income');
        $this->spend($this->b['bank'], $this->b['food'], 120000, '2026-11-03');

        $m = $this->ask('Am I profitable this month?');

        $this->assertStringContainsString('surplus by ZAR 3,800.00', $m->answer_text);
        $this->assertStringContainsString('cash view', implode(' ', $m->answer['notes']));

        $this->spend($this->b['bank'], $this->b['food'], 900000, '2026-11-04');
        $this->assertStringContainsString('deficit by ZAR 5,200.00', $this->ask('am I making a loss this month')->answer_text);
    }

    public function test_upcoming_bills_come_from_recurring_expenses_and_an_empty_ledger_says_no_data(): void
    {
        $this->assertSame('no_data', $this->ask('What bills are coming up?')->status);

        RecurringRule::factory()->create([
            'team_id' => $this->b['team']->id, 'user_id' => null, 'account_id' => $this->b['bank']->id, 'description' => 'Office rent', 'type' => 'expense',
            'amount_cents' => 850000, 'currency' => 'ZAR', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1,
            'start_date' => '2026-01-25', 'next_occurrence_date' => '2026-11-25',
        ]);

        $m = $this->ask('What bills are coming up?');

        $this->assertSame('answered', $m->status);
        $this->assertStringContainsString('Office rent', $m->answer_text);
        $this->assertStringContainsString('ZAR 8,500.00', $m->answer_text);
        $this->assertStringContainsString('2026-11-25', $m->answer_text);
    }

    public function test_projected_cash_is_an_estimate_with_its_assumption_and_needs_recurring_items(): void
    {
        $this->assertSame('no_data', $this->ask('What will my cash be in 60 days?')->status);

        RecurringRule::factory()->create([
            'team_id' => $this->b['team']->id, 'user_id' => null, 'account_id' => $this->b['bank']->id, 'description' => 'Rent', 'type' => 'expense',
            'amount_cents' => 100000, 'currency' => 'ZAR', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1,
            'start_date' => '2026-01-20', 'next_occurrence_date' => '2026-11-20',
        ]);

        $m = $this->ask('What will my cash be in 60 days?');

        $this->assertSame('estimate', $m->basis);
        $this->assertStringContainsString('ZAR 8,000.00', $m->answer_text); // 10,000.00 less rent on 20 Nov and 20 Dec
        $this->assertStringContainsString('not a promise', implode(' ', $m->answer['notes']));
    }

    public function test_budget_performance_reports_use_and_over_limit(): void
    {
        $this->assertSame('no_data', $this->ask('How are my budgets doing?')->status);

        Budget::factory()->create(['team_id' => $this->b['team']->id, 'user_id' => null, 'category_id' => $this->b['food']->id, 'amount_cents' => 100000, 'currency' => 'ZAR', 'month' => 11, 'year' => 2026]);
        $this->spend($this->b['bank'], $this->b['food'], 125000, '2026-11-05');

        $m = $this->ask('How are my budgets doing?');

        $this->assertStringContainsString('1 of your 1 budget', $m->answer_text);
        $this->assertStringContainsString('125% used', $m->answer['rows'][0]['cells'][2]);
    }

    public function test_can_i_afford_tests_the_amount_against_the_projected_low_and_asks_for_an_amount(): void
    {
        $this->assertStringContainsString('I need an amount', $this->ask('Can I afford this?')->answer_text);

        $yes = $this->ask('Can I afford R5,000?');
        $this->assertStringContainsString('yes', $yes->answer_text);
        $this->assertStringContainsString('ZAR 5,000.00', $yes->answer_text);
        $this->assertSame('estimate', $yes->basis);
        $this->assertStringContainsString('not financial advice', implode(' ', $yes->answer['notes']));

        $no = $this->ask('Can I afford R15,000?');
        $this->assertStringContainsString('not comfortably', $no->answer_text);
        $this->assertStringContainsString('-ZAR 5,000.00', $no->answer_text);
    }

    public function test_receivables_overdue_vat_and_tax_say_i_dont_have_that_when_nothing_is_recorded(): void
    {
        foreach (['Who owes me money?', 'Which invoices are overdue?', 'What is my VAT liability so far?', 'What tax do I need to prepare for?'] as $q) {
            $m = $this->ask($q);
            $this->assertSame('no_data', $m->status, $q);
            $this->assertStringContainsString("don't have that information", $m->answer_text, $q);
        }
    }

    public function test_who_owes_me_and_vat_read_the_general_ledger_when_accounting_is_on(): void
    {
        $this->enableAccounting($this->b['context']);

        $party = Party::factory()->create(['team_id' => $this->b['team']->id, 'user_id' => null, 'name' => 'Karoo Foods', 'type' => 'customer']);
        LedgerContext::run($this->b['context'], function () use ($party) {
            $poster = app(JournalPoster::class);
            $poster->post(new PostingRequest(
                CarbonImmutable::parse('2026-11-05'), 'manual', 'inv-1', 1, 'Invoice 1', [
                    new PostingLine('ar', debitCents: 115000, partyId: $party->id),
                    new PostingLine('sales', creditCents: 100000),
                    new PostingLine('vat_output', creditCents: 15000),
                ], $this->b['owner']->id,
            ));
            $poster->post(new PostingRequest(
                CarbonImmutable::parse('2026-11-06'), 'manual', 'bill-1', 1, 'Bill 1', [
                    new PostingLine('uncategorised_expense', debitCents: 20000),
                    new PostingLine('vat_input', debitCents: 3000),
                    new PostingLine('ap', creditCents: 23000),
                ], $this->b['owner']->id,
            ));
        });

        $owed = $this->ask('Who owes me money?');
        $this->assertStringContainsString('Karoo Foods', $owed->answer_text);
        $this->assertStringContainsString('ZAR 1,150.00', $owed->answer_text);

        $vat = $this->ask('What is my VAT liability so far?');
        $this->assertStringContainsString('ZAR 150.00', $vat->answer_text);   // collected
        $this->assertStringContainsString('ZAR 30.00', $vat->answer_text);    // claimable
        $this->assertStringContainsString('ZAR 120.00 is owed to SARS', $vat->answer_text);
        $this->assertStringContainsString('not a VAT201', implode(' ', $vat->answer['notes']));

        // overdue still needs invoices with due dates
        $this->assertSame('no_data', $this->ask('Which invoices are overdue?')->status);
    }

    public function test_needs_attention_lists_the_state_line_and_says_nothing_when_clean(): void
    {
        $this->assertStringContainsString('Nothing needs your attention', $this->ask('What needs attention?')->answer_text);

        $this->spend($this->b['bank'], null, 12345, '2026-11-10', 'Mystery');

        $m = $this->ask('Which transactions need attention?');
        $this->assertStringContainsString('no category', $m->answer_text);
    }

    public function test_unrecognised_questions_are_refused_politely_with_no_numbers(): void
    {
        $m = $this->ask('What is the weather like?');

        $this->assertSame('unrecognised', $m->status);
        $this->assertSame('unknown', $m->intent);
        $this->assertDoesNotMatchRegularExpression('/\d/', $m->answer_text);
    }

    public function test_another_ledgers_data_is_never_used(): void
    {
        $other = $this->business('Other Ltd');
        $this->spend($other['bank'], $other['food'], 77700000, '2026-11-03');
        $this->spend($this->b['bank'], $this->b['food'], 10000, '2026-11-03');

        $m = $this->ask('How much did I spend this month?');

        $this->assertStringContainsString('ZAR 100.00', $m->answer_text);
        $this->assertStringNotContainsString('777,000', json_encode($m->answer));
    }

    public function test_a_person_outside_the_ledger_is_refused_and_nothing_is_stored(): void
    {
        $stranger = $this->user();

        try {
            $this->ask('How much did I spend this month?', $stranger);
            $this->fail('expected a refusal');
        } catch (AuthorizationException) {
            $this->assertSame(0, AssistantMessage::query()->forContext($this->b['context'])->count());
        }
    }

    public function test_a_read_only_member_can_ask_but_cannot_get_a_draft(): void
    {
        $viewer = $this->memberOf($this->b['team'], 'viewer');

        $this->assertContains($this->ask('Am I profitable this month?', $viewer)->status, ['answered', 'no_data']);

        $m = $this->ask('Add an expense of R250 for fuel', $viewer);
        $this->assertSame(Answer::REFUSED, $m->status);
        $this->assertSame(0, $m->drafts->count());
    }
}
