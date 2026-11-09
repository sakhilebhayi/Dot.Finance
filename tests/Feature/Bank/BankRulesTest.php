<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\Bank\BankRule;
use App\Models\Category;
use App\Models\User;
use App\Services\Bank\BankRuleEngine;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBankFixtures;
use Tests\TestCase;

class BankRulesTest extends TestCase
{
    use BuildsBankFixtures;
    use RefreshDatabase;

    private User $user;

    private LedgerContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->ctx = LedgerContext::personal($this->user);
    }

    private function rule(array $o): BankRule
    {
        return BankRule::create($this->ctx->ownerAttributes() + $o + ['name' => 'r']);
    }

    private function line(string $description, int $cents, ?string $reference = null): object
    {
        return (object) ['description' => $description, 'reference' => $reference, 'amount_cents' => $cents];
    }

    public function test_operators_directions_and_amount_bands(): void
    {
        $this->rule(['name' => 'contains', 'pattern' => 'woolies', 'priority' => 3]);
        $this->rule(['name' => 'starts', 'pattern' => 'uber', 'operator' => 'starts_with', 'direction' => 'out', 'priority' => 2]);
        $this->rule(['name' => 'equals', 'pattern' => 'salary', 'operator' => 'equals', 'direction' => 'in', 'priority' => 1]);
        $this->rule(['name' => 'band', 'pattern' => 'fuel', 'min_cents' => 10_000, 'max_cents' => 90_000, 'priority' => 4]);
        $engine = BankRuleEngine::for($this->ctx, 0);

        $this->assertSame('contains', $engine->match($this->line('POS  WOOLIES   Claremont', -100))->name);
        $this->assertSame('starts', $engine->match($this->line('UBER *TRIP', -5_000))->name);
        $this->assertNull($engine->match($this->line('PAYMENT UBER', -5_000)));
        $this->assertNull($engine->match($this->line('UBER REFUND', 5_000)), 'direction out only');
        $this->assertSame('equals', $engine->match($this->line('Salary', 5_000_000))->name);
        $this->assertNull($engine->match($this->line('Salary October', 5_000_000)));
        $this->assertNull($engine->match($this->line('FUEL', -5_000)));
        $this->assertSame('band', $engine->match($this->line('FUEL', -50_000))->name);
        $this->assertNull($engine->match($this->line('FUEL', -95_000)));
    }

    public function test_the_lowest_order_wins_and_an_inactive_rule_is_ignored(): void
    {
        $this->rule(['name' => 'late', 'pattern' => 'shop', 'priority' => 50]);
        $this->rule(['name' => 'early', 'pattern' => 'shop', 'priority' => 5]);
        $this->rule(['name' => 'off', 'pattern' => 'shop', 'priority' => 1, 'is_active' => false]);

        $this->assertSame('early', BankRuleEngine::for($this->ctx, 0)->match($this->line('A SHOP', -1))->name);
    }

    public function test_a_rule_for_one_account_does_not_apply_to_another(): void
    {
        $a = Account::factory()->create(['user_id' => $this->user->id]);
        $b = Account::factory()->create(['user_id' => $this->user->id]);
        $this->rule(['name' => 'only a', 'pattern' => 'x', 'account_id' => $a->id]);

        $this->assertNotNull(BankRuleEngine::for($this->ctx, $a->id)->match($this->line('x', -1)));
        $this->assertNull(BankRuleEngine::for($this->ctx, $b->id)->match($this->line('x', -1)));
    }

    public function test_rules_are_managed_through_the_screens_with_validation_and_audit(): void
    {
        $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries', 'type' => 'expense']);
        $income = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Sales', 'type' => 'income']);
        $this->inPersonal($this->user);

        $this->post(route('bank.rules.store'), ['name' => 'W', 'pattern' => 'woolworths', 'match_field' => 'any', 'operator' => 'contains', 'direction' => 'out', 'category_id' => $groceries->id, 'min_amount' => '1.00', 'max_amount' => '5000.00', 'priority' => 10])
            ->assertRedirect(route('bank.rules.index'));
        $rule = BankRule::query()->forContext($this->ctx)->firstOrFail();
        $this->assertSame(100, $rule->min_cents);
        $this->assertSame(500_000, $rule->max_cents);
        $this->assertDatabaseHas('finance_audit_log', ['subject_type' => 'bank_rule', 'subject_id' => $rule->id, 'action' => 'created']);

        // the category must fit the direction; amounts must be real money; the band must make sense
        $this->post(route('bank.rules.store'), ['name' => 'bad', 'pattern' => 'x', 'match_field' => 'any', 'operator' => 'contains', 'direction' => 'out', 'category_id' => $income->id])->assertSessionHasErrors('category_id');
        $this->post(route('bank.rules.store'), ['name' => 'bad', 'pattern' => 'x', 'match_field' => 'any', 'operator' => 'contains', 'direction' => 'any', 'min_amount' => '1e3'])->assertSessionHasErrors('min_amount');
        $this->post(route('bank.rules.store'), ['name' => 'bad', 'pattern' => 'x', 'match_field' => 'any', 'operator' => 'contains', 'direction' => 'any', 'min_amount' => '9.00', 'max_amount' => '1.00'])->assertSessionHasErrors('min_amount');
        $this->post(route('bank.rules.store'), ['name' => 'bad', 'pattern' => '', 'match_field' => 'regex', 'operator' => 'contains', 'direction' => 'any'])->assertSessionHasErrors(['pattern', 'match_field']);

        $this->put(route('bank.rules.update', $rule), ['name' => 'W2', 'pattern' => 'woolworths', 'match_field' => 'any', 'operator' => 'starts_with', 'direction' => 'out', 'is_active' => 0])->assertRedirect();
        $this->assertFalse($rule->fresh()->is_active);
        $this->get(route('bank.rules.index'))->assertOk()->assertSee('W2');

        $this->delete(route('bank.rules.destroy', $rule))->assertRedirect();
        $this->assertDatabaseCount('bank_rules', 0);
        $this->assertDatabaseHas('finance_audit_log', ['subject_type' => 'bank_rule', 'action' => 'deleted']);
    }
}
