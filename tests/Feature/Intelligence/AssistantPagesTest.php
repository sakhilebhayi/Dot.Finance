<?php

namespace Tests\Feature\Intelligence;

use App\Models\Intelligence\AssistantDraft;
use App\Models\Intelligence\AssistantMessage;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsIntelFixtures;
use Tests\TestCase;

class AssistantPagesTest extends TestCase
{
    use BuildsIntelFixtures;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixTime();
        $this->b = $this->business();
        RateLimiter::clear('finance-assistant');
    }

    private function asOwner()
    {
        return $this->inCompany($this->b['owner'], $this->b['team']);
    }

    private function messages($user)
    {
        return LedgerContext::run($this->b['context'], fn () => AssistantMessage::query()->askedBy($user)->get());
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        foreach (['intel.assistant.index', 'intel.attention.index', 'intel.notifications.index', 'intel.integrations.index'] as $name) {
            $this->get(route($name))->assertRedirect('/login');
        }
        $this->post(route('intel.assistant.ask'), ['question' => 'x y z'])->assertRedirect('/login');
    }

    public function test_asking_shows_the_answer_with_its_basis_figures_and_sources(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 123450, '2026-11-03', 'Groceries');

        $this->asOwner()->get(route('intel.assistant.index'))->assertOk()->assertSee('Ask a question')->assertSee('No questions yet.')->assertSee('Can I afford R5,000?');
        $this->post(route('intel.assistant.ask'), ['question' => 'How much did I spend this month?'])->assertRedirectContains('#answer-');

        $page = $this->get(route('intel.assistant.index'))->assertOk();
        $page->assertSee('You spent ZAR 1,234.50 so far this month.')->assertSee('Calculated')->assertSee('Expenses, this month so far')->assertSee('Food')->assertSee('Delete this');
        $this->assertCount(1, $this->messages($this->b['owner']));
    }

    public function test_a_suggested_question_is_a_button_that_asks_it(): void
    {
        $this->asOwner()->post(route('intel.assistant.ask'), ['question' => 'Who owes me money?'])->assertRedirect();

        $this->get(route('intel.assistant.index'))->assertSee('I don&#039;t have that information', false)->assertSee('No information');
    }

    public function test_every_suggestion_chip_submits_its_own_text_and_is_not_blocked_by_the_empty_required_field(): void
    {
        $html = $this->asOwner()->get(route('intel.assistant.index'))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new \DOMXPath($dom);

        $chips = $xpath->query('//ul[contains(@class,"intel-suggest")]//button');
        $this->assertGreaterThanOrEqual(5, $chips->length, 'the page offers the suggested questions');

        foreach ($chips as $chip) {
            $form = $xpath->query('ancestor::form', $chip)->item(0);
            $this->assertNotNull($form, 'a chip sits in a form');
            // The browser validates every required control of the chip's form: none may be empty.
            $this->assertSame(0, $xpath->query('.//*[@required]', $form)->length, 'a chip form must not contain the required free-text field');
            $hidden = $xpath->query('.//input[@type="hidden"][@name="question"]', $form)->item(0);
            $this->assertNotNull($hidden, 'the chip carries its own question');
            $this->assertSame(trim($chip->textContent), $hidden->getAttribute('value'), 'the chip asks what it says');
        }

        // The typed field is still required and is in a form of its own.
        $typed = $xpath->query('//input[@name="question"][@required]')->item(0);
        $this->assertNotNull($typed);
        $this->assertSame(0, $xpath->query('ancestor::form//ul[contains(@class,"intel-suggest")]', $typed)->length);
    }

    public function test_asking_each_suggestion_stores_exactly_that_question(): void
    {
        config(['intelligence.assistant.per_minute' => 60]);
        $this->asOwner();
        foreach (\App\Services\Intelligence\Intent::suggestions() as $suggestion) {
            $this->post(route('intel.assistant.ask'), ['question' => $suggestion])->assertRedirectContains('#answer-');
        }

        $asked = $this->messages($this->b['owner'])->pluck('question')->all();
        foreach (\App\Services\Intelligence\Intent::suggestions() as $suggestion) {
            $this->assertContains($suggestion, $asked);
        }
    }

    public function test_an_unrecognised_question_is_answered_honestly_without_figures(): void
    {
        $this->asOwner()->post(route('intel.assistant.ask'), ['question' => 'Tell me a joke about money'])->assertRedirect();

        $this->get(route('intel.assistant.index'))->assertSee('I can&#039;t answer that one.', false)->assertSee('Not understood');
    }

    public function test_the_question_is_validated(): void
    {
        $this->asOwner();
        $this->post(route('intel.assistant.ask'), ['question' => ''])->assertSessionHasErrors('question');
        $this->post(route('intel.assistant.ask'), ['question' => 'ab'])->assertSessionHasErrors('question');
        $this->post(route('intel.assistant.ask'), ['question' => str_repeat('a', 501)])->assertSessionHasErrors('question');
        $this->post(route('intel.assistant.ask'), ['question' => ['x']])->assertSessionHasErrors('question');
        $this->assertCount(0, $this->messages($this->b['owner']));
    }

    public function test_the_questions_are_rate_limited_per_person(): void
    {
        config(['intelligence.assistant.per_minute' => 3]);
        $this->asOwner();

        foreach (range(1, 3) as $i) {
            $this->post(route('intel.assistant.ask'), ['question' => 'What is my cash position?'])->assertRedirect();
        }
        $this->post(route('intel.assistant.ask'), ['question' => 'What is my cash position?'])->assertStatus(429);

        // Someone else is not held up by it.
        $colleague = $this->memberOf($this->b['team'], 'admin');
        $this->inCompany($colleague, $this->b['team'])->post(route('intel.assistant.ask'), ['question' => 'What is my cash position?'])->assertRedirect();
    }

    public function test_history_is_private_deletable_and_per_ledger(): void
    {
        $this->asOwner()->post(route('intel.assistant.ask'), ['question' => 'How much cash is in the bank right now?']);
        $colleague = $this->memberOf($this->b['team'], 'admin');
        $this->inCompany($colleague, $this->b['team'])->post(route('intel.assistant.ask'), ['question' => 'Are my budgets on track this month?']);

        $mine = $this->messages($this->b['owner'])->first();
        $theirs = $this->messages($colleague)->first();

        $this->inCompany($colleague, $this->b['team'])->get(route('intel.assistant.index'))->assertSee('Are my budgets on track this month?')->assertDontSee('How much cash is in the bank right now?');
        $this->delete(route('intel.assistant.destroy', $mine))->assertNotFound();
        $this->assertNotNull($mine->fresh());

        $this->inCompany($this->b['owner'], $this->b['team'])->delete(route('intel.assistant.destroy', $mine))->assertRedirect();
        $this->assertNull($mine->fresh());
        $this->assertNotNull($theirs->fresh());

        $this->asOwner()->post(route('intel.assistant.ask'), ['question' => 'How much cash is in the bank right now?']);
        $this->delete(route('intel.assistant.clear'))->assertRedirect();
        $this->assertCount(0, $this->messages($this->b['owner']));
        $this->assertCount(1, $this->messages($colleague), 'clearing mine does not clear theirs');
    }

    public function test_a_viewer_can_ask_but_the_draft_button_never_appears_for_them(): void
    {
        $viewer = $this->memberOf($this->b['team'], 'viewer');

        $this->inCompany($viewer, $this->b['team'])->post(route('intel.assistant.ask'), ['question' => 'Add an expense of R250 for fuel'])->assertRedirect();

        $this->get(route('intel.assistant.index'))->assertOk()->assertSee('cannot draft that for you')->assertDontSee('Review and confirm');
    }

    public function test_the_pages_are_csp_clean(): void
    {
        $this->asOwner()->post(route('intel.assistant.ask'), ['question' => 'Add an expense of R250 for fuel']);
        $draft = LedgerContext::run($this->b['context'], fn () => AssistantDraft::query()->firstOrFail());

        foreach ([route('intel.assistant.index'), route('intel.assistant.draft', $draft), route('intel.attention.index'), route('intel.notifications.index'), route('intel.notifications.preferences'), route('intel.integrations.index'), route('intel.integrations.emall.index')] as $url) {
            $response = $this->get($url)->assertOk();
            $html = $response->getContent();

            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, $url.' has an inline style');
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $html, $url.' has an inline handler');
            $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html), $url.' should have exactly one h1');
            $this->assertStringContainsString("script-src 'self' 'nonce-", $response->headers->get('Content-Security-Policy'));
            foreach ((array) preg_match_all('/<script(?![^>]*nonce=)[^>]*>/i', $html, $m) ? $m[0] : [] as $tag) {
                $this->assertStringContainsString('src=', $tag, $url.' has an inline script without a nonce');
            }
        }
    }

    public function test_the_navigation_lists_the_new_pages(): void
    {
        $this->asOwner()->get(route('dashboard'))->assertSee('Assistant')->assertSee('What needs attention')->assertSee('Notifications')->assertSee('Integrations');
    }
}
