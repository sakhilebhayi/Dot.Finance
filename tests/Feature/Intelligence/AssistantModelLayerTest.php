<?php

namespace Tests\Feature\Intelligence;

use App\Contracts\Intelligence\ModelClient;
use App\Services\Intelligence\AnthropicClient;
use App\Services\Intelligence\FinanceAssistant;
use App\Services\Intelligence\ModelProseWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsIntelFixtures;
use Tests\Support\FakeModelClient;
use Tests\TestCase;

class AssistantModelLayerTest extends TestCase
{
    use BuildsIntelFixtures;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixTime();
        $this->b = $this->business();
        $this->spend($this->b['bank'], $this->b['food'], 123450, '2026-11-03');
    }

    private function useModel(FakeModelClient $fake, bool $enabled = true): void
    {
        config(['intelligence.model.enabled' => $enabled]);
        $this->app->instance(ModelClient::class, $fake);
        $this->app->forgetInstance(FinanceAssistant::class);
        $this->app->forgetInstance(ModelProseWriter::class);
    }

    private function ask(string $q)
    {
        return app(FinanceAssistant::class)->ask($this->b['owner'], $this->b['context'], $q);
    }

    public function test_the_model_layer_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('intelligence.model.enabled'));
        Http::fake();

        $m = $this->ask('How much did I spend this month?');

        $this->assertFalse($m->model_used);
        $this->assertSame('calculated', $m->basis);
        Http::assertNothingSent();
    }

    public function test_enabled_in_config_but_without_a_key_nothing_is_called(): void
    {
        config(['intelligence.model.enabled' => true, 'services.anthropic.api_key' => '']);
        Http::fake();

        $this->assertFalse($this->ask('How much did I spend this month?')->model_used);
        Http::assertNothingSent();
    }

    public function test_a_rewording_that_keeps_every_figure_is_used_and_labelled_as_ai(): void
    {
        $fake = new FakeModelClient('This month so far you have spent ZAR 1,234.50 in total.');
        $this->useModel($fake);

        $m = $this->ask('How much did I spend this month?');

        $this->assertTrue($m->model_used);
        $this->assertFalse($m->model_rejected);
        $this->assertSame('ai_summary', $m->basis);
        $this->assertSame('This month so far you have spent ZAR 1,234.50 in total.', $m->answer_text);
        // The model only ever saw the computed answer, never a question's raw ledger rows.
        $this->assertStringContainsString('ZAR 1,234.50', $fake->calls[0]['user']);
        $this->assertStringNotContainsString($this->b['bank']->name, $fake->calls[0]['user']);
        // The structured facts that the person can check are untouched.
        $this->assertSame('ZAR 1,234.50', $m->answer['facts'][0]['value']);
    }

    public function test_a_rewording_with_a_changed_or_invented_number_is_discarded_and_the_computed_sentence_stands(): void
    {
        foreach (['You spent roughly ZAR 1,200 this month.', 'You spent ZAR 1,234.50, which is 15% more than usual.', 'You spent double what you did last month.'] as $bad) {
            $this->useModel(new FakeModelClient($bad));

            $m = $this->ask('How much did I spend this month?');

            $this->assertFalse($m->model_used, $bad);
            $this->assertTrue($m->model_rejected, $bad);
            $this->assertSame('calculated', $m->basis);
            $this->assertStringContainsString('You spent ZAR 1,234.50', $m->answer_text);
        }
    }

    public function test_a_failing_or_empty_model_changes_nothing(): void
    {
        $this->useModel(new FakeModelClient(null, throws: true));
        $m = $this->ask('How much did I spend this month?');
        $this->assertFalse($m->model_used);
        $this->assertFalse($m->model_rejected);

        $this->useModel(new FakeModelClient('   '));
        $this->assertFalse($this->ask('How much did I spend this month?')->model_used);
    }

    public function test_no_data_and_refusals_are_never_sent_to_the_model(): void
    {
        $fake = new FakeModelClient('You have lots of VAT.');
        $this->useModel($fake);

        $m = $this->ask('What is my VAT liability so far?');

        $this->assertSame('no_data', $m->status);
        $this->assertSame([], $fake->calls);
        $this->assertStringNotContainsString('lots of VAT', $m->answer_text);
    }

    public function test_the_model_may_only_classify_into_a_known_intent(): void
    {
        $this->useModel(new FakeModelClient('spend'));
        $m = $this->ask('give me the damage report');
        $this->assertSame('spend', $m->intent);
        $this->assertSame('answered', $m->status);

        // An intent that does not exist, or a draft intent, is refused: the model cannot invent a query or an action.
        foreach (['drop table transactions', 'draft_transaction', 'all_transactions_export'] as $evil) {
            $this->useModel(new FakeModelClient($evil));
            $m = $this->ask('give me the damage report');
            $this->assertSame('unknown', $m->intent, $evil);
            $this->assertSame('unrecognised', $m->status);
        }
    }

    public function test_the_anthropic_client_posts_only_the_prompt_to_the_provider(): void
    {
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.model' => 'claude-test']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push(['content' => [['type' => 'text', 'text' => 'ok']]])->push([], 500)]);

        $out = (new AnthropicClient)->complete('sys', 'usr', 50);

        $this->assertSame('ok', $out);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.anthropic.com/v1/messages' && $r->hasHeader('x-api-key', 'test-key') && $r['model'] === 'claude-test' && $r['messages'][0]['content'] === 'usr');

        $this->assertNull((new AnthropicClient)->complete('sys', 'usr'));
    }
}
