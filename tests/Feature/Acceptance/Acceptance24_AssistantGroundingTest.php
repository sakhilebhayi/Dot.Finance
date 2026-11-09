<?php

namespace Tests\Feature\Acceptance;

use App\Models\Account;
use App\Models\Intelligence\AssistantMessage;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Services\Intelligence\FinanceAssistant;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\SalesProfiles;
use App\Support\Ledger\LedgerContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * Owner criterion 24: AI financial answers use authorised real data. The assistant answers from the books of the ledger it was asked
 * in, through the same services as the reports, labels what it calculated, says "I don't have that information" when the ledger holds
 * nothing, and never shows ledger A's customers, balances or history in ledger B, not even to a person who owns both. No model is
 * configured in the test run (a model may only reword a sentence, and only with numbers the facts contain), so these are the
 * rule-based answers.
 */
class Acceptance24_AssistantGroundingTest extends AcceptanceCase
{
    private Team $second;

    private Team $empty;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('finance-assistant');
        $this->runMonth();

        // a second company the same person owns, with one customer who owes 1,111.11
        $this->second = $this->company($this->owner, 'Zed Holdings (Pty) Ltd');
        $secondCtx = LedgerContext::company($this->second);
        Account::factory()->forCompany($this->second)->create(['name' => 'Zed cheque', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 777_700, 'opening_balance_date' => '2026-10-01']);
        $this->enableAccounting($secondCtx, 'ZAR');
        LedgerContext::run($secondCtx, function () use ($secondCtx) {
            app(SalesProfiles::class)->save($secondCtx, ['legal_name' => 'Zed Holdings (Pty) Ltd', 'vat_registered' => false, 'address' => ['line1' => '2 Zed Way', 'city' => 'Pretoria', 'postal_code' => '0001', 'country' => 'South Africa'], 'default_terms_days' => 30], $this->owner->id);
            $customer = Party::create($secondCtx->ownerAttributes() + ['type' => 'customer', 'name' => 'Zebra Customer', 'legal_name' => 'Zebra Customer (Pty) Ltd', 'status' => 'active', 'payment_terms_days' => 30, 'billing_address' => ['line1' => '1 Stripe St', 'city' => 'Durban', 'country' => 'South Africa']]);
            $draft = app(DocumentDrafts::class)->create($secondCtx, $this->owner, ['kind' => 'tax_invoice', 'party_id' => $customer->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-10'], [['description' => 'Stripes', 'quantity' => '1', 'unit_cents' => 111_111, 'tax_code' => 'ZA_VAT_STD']]);
            app(DocumentIssuer::class)->issue($draft, $this->owner);
        });

        $this->empty = $this->company($this->owner, 'Blank Slate (Pty) Ltd');
    }

    private function ask(string $question, ?Team $team = null, $user = null): TestResponse
    {
        return $this->inCompany($user ?? $this->owner, $team ?? $this->company)->post(route('intel.assistant.ask'), ['question' => $question]);
    }

    private function history(Team $team, $user = null): string
    {
        $html = $this->inCompany($user ?? $this->owner, $team)->get(route('intel.assistant.index'))->assertOk()->getContent();

        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES));
    }

    private function lastMessage(LedgerContext $context): AssistantMessage
    {
        return LedgerContext::run($context, fn () => AssistantMessage::query()->latest('id')->firstOrFail());
    }

    public function test_the_answers_are_the_numbers_in_the_books_and_say_how_they_were_worked_out(): void
    {
        $ctx = $this->ledger;
        $this->ask('Who owes me money?')->assertRedirectContains('#answer-');
        $owes = $this->lastMessage($ctx);
        $this->assertSame(['answered', 'calculated'], [$owes->status, $owes->basis]);
        $this->assertStringContainsString('9,000.00', $owes->answer_text);
        $this->assertStringContainsString('Acme Retail', $owes->answer_text.json_encode($owes->answer));
        $this->assertSame($this->report('ageing-receivables')->cell('Total', 'total'), 900_000, 'the same figure the ageing report shows');

        $this->ask('What is my cash position?');
        $this->assertStringContainsString('119,290.00', $this->lastMessage($ctx)->answer_text);

        $this->ask('How much VAT do I owe?');
        $vat = $this->lastMessage($ctx);
        $this->assertSame('answered', $vat->status);
        $this->assertStringContainsString('3,210.00', $vat->answer_text);

        $page = $this->history($this->company);
        $this->assertStringContainsString('Calculated', $page);
        $this->assertStringNotContainsString('Zebra', $page);
    }

    public function test_ledger_a_never_sees_ledger_bs_data_and_ledger_b_never_sees_a_even_for_a_person_who_owns_both(): void
    {
        $this->ask('Who owes me money?');
        $this->ask('Who owes me money?', $this->second);

        $a = $this->lastMessage($this->ledger);
        $b = $this->lastMessage(LedgerContext::company($this->second));
        $this->assertStringContainsString('9,000.00', $a->answer_text);
        $this->assertStringNotContainsString('1,111.11', $a->answer_text.json_encode($a->answer));
        $this->assertStringNotContainsString('Zebra', $a->answer_text.json_encode($a->answer));
        $this->assertStringContainsString('1,111.11', $b->answer_text.json_encode($b->answer));
        $this->assertStringNotContainsString('Acme', $b->answer_text.json_encode($b->answer));
        $this->assertStringNotContainsString('9,000.00', $b->answer_text.json_encode($b->answer));

        // the history shown is the history of the ledger in view
        $this->assertStringNotContainsString('Zebra', $this->history($this->company));
        $this->assertStringNotContainsString('Acme', $this->history($this->second));
        $this->ask('What is my cash position?', $this->second);
        $cash = $this->lastMessage(LedgerContext::company($this->second))->answer_text;
        $this->assertStringContainsString('7,777.00', $cash);
        $this->assertStringNotContainsString('119,290.00', $cash);
    }

    public function test_a_ledger_with_nothing_in_it_says_it_does_not_have_the_information_instead_of_a_zero_or_a_guess(): void
    {
        foreach (['Who owes me money?', 'What is my cash position?', 'How much did I spend this month?', 'Show my upcoming bills', 'Am I profitable this month?'] as $question) {
            $this->ask($question, $this->empty)->assertRedirect();
            $m = $this->lastMessage(LedgerContext::company($this->empty));
            $this->assertSame('no_data', $m->status, $question);
            $this->assertMatchesRegularExpression("/I don't have/", $m->answer_text, $question);
            $this->assertDoesNotMatchRegularExpression('/ZAR 0\.00/', $m->answer_text, "{$question}: no zero in disguise");
        }
        $page = $this->history($this->empty);
        $this->assertStringContainsString("I don't have that information", $page);
    }

    public function test_a_question_it_cannot_answer_is_refused_in_words_and_without_numbers(): void
    {
        $this->ask('Tell me a joke about money');
        $m = $this->lastMessage($this->ledger);
        $this->assertSame('unknown', $m->intent);
        $this->assertStringContainsString("I can't answer that one", $m->answer_text);
        $this->assertDoesNotMatchRegularExpression('/\d,\d{3}\.\d\d|ZAR \d/', $m->answer_text);
    }

    public function test_only_people_with_access_to_a_ledger_can_ask_about_it_and_history_is_private_to_the_asker(): void
    {
        $stranger = $this->user();
        $this->expectException(AuthorizationException::class);
        try {
            app(FinanceAssistant::class)->ask($stranger, $this->ledger, 'Who owes me money?');
        } finally {
            $this->assertSame(0, AssistantMessage::query()->withoutGlobalScopes()->where('asker_id', $stranger->id)->count());
        }
    }

    public function test_a_session_that_claims_a_company_the_person_is_not_in_answers_from_their_own_ledger_only(): void
    {
        $stranger = $this->user();
        $this->inCompany($stranger, $this->company)->post(route('intel.assistant.ask'), ['question' => 'Who owes me money?'])->assertRedirect();
        $page = preg_replace('/\s+/', ' ', strip_tags($this->inCompany($stranger, $this->company)->get(route('intel.assistant.index'))->getContent()));
        $this->assertStringNotContainsString('Acme', $page);
        $this->assertStringNotContainsString('9,000.00', $page);
    }

    public function test_a_viewer_may_ask_and_gets_the_same_numbers(): void
    {
        $viewer = $this->memberAs('viewer');
        $this->ask('What is my cash position?', $this->company, $viewer)->assertRedirect();
        $this->assertStringContainsString('119,290.00', $this->lastMessage($this->ledger)->answer_text);
    }
}
