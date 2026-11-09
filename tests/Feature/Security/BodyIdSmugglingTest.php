<?php

namespace Tests\Feature\Security;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\RunsRouteProbes;
use Tests\Concerns\SeedsSentinelLedgers;
use Tests\TestCase;

/**
 * R-44 security sweep, part 1b: ids smuggled in the REQUEST BODY (not the URL) into the money-moving forms of every
 * module. The signed-in owner of one company posts, in turn, the ids of their own personal ledger, of another company
 * and of another person's personal ledger. Each attempt must be refused (validation error, 403 or 404) and must change
 * no row anywhere. A control request with the person's own ids proves the body was otherwise acceptable, so a refusal
 * is about the foreign id and not about a missing field.
 */
class BodyIdSmugglingTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;
    use RunsRouteProbes;
    use SeedsSentinelLedgers;

    private User $me;

    private Team $mine;

    /** @var array<string, array> label => ids of that ledger */
    private array $foreign = [];

    private array $own;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\TaxModuleRulesSeeder::class);
        $this->me = $this->user();
        $stranger = $this->user();
        $this->mine = $this->company($this->me, 'Mine Ltd');
        $theirs = $this->company($stranger, 'Theirs Ltd');

        $this->own = $this->seedContext('mine', ['user_id' => null, 'team_id' => $this->mine->id], company: $this->mine);
        $this->foreign = [
            'my personal ledger' => $this->seedContext('personal', ['user_id' => $this->me->id, 'team_id' => null]),
            'another company' => $this->seedContext('theirs', ['user_id' => null, 'team_id' => $theirs->id], company: $theirs),
            'a stranger' => $this->seedContext('stranger', ['user_id' => $stranger->id, 'team_id' => null]),
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: callable(array): array}> */
    private function attempts(): array
    {
        $day = now()->toDateString();

        return [
            'sales payment for a foreign customer' => ['post', 'sales.payments.store', fn ($i) => ['party_id' => $i['party'], 'cash_account_id' => $this->own['account'], 'amount' => '10.00', 'paid_on' => $day, 'idempotency_key' => 'k1']],
            'sales payment into a foreign bank account' => ['post', 'sales.payments.store', fn ($i) => ['party_id' => $this->own['party'], 'cash_account_id' => $i['account'], 'amount' => '10.00', 'paid_on' => $day, 'idempotency_key' => 'k2']],
            'sales payment allocated to a foreign invoice' => ['post', 'sales.payments.store', fn ($i) => ['party_id' => $this->own['party'], 'cash_account_id' => $this->own['account'], 'amount' => '10.00', 'paid_on' => $day, 'idempotency_key' => 'k3', 'allocations' => [(string) $i['document'] => '10.00']]],
            'sales invoice for a foreign customer' => ['post', 'sales.documents.store', fn ($i) => ['kind' => 'tax_invoice', 'party_id' => $i['party'], 'issue_date' => $day, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00']]]],
            'sales invoice crediting a foreign document' => ['post', 'sales.documents.store', fn ($i) => ['kind' => 'credit_note', 'party_id' => $this->own['party'], 'issue_date' => $day, 'parent_id' => $i['document'], 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00']]]],
            'sales invoice line into a foreign income account' => ['post', 'sales.documents.store', fn ($i) => ['kind' => 'tax_invoice', 'party_id' => $this->own['party'], 'issue_date' => $day, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'income_account_id' => $i['glAccount']]]]],
            'sales refund from a foreign payment' => ['post', 'sales.refunds.store', fn ($i) => ['source_payment_id' => $i['payment'], 'amount' => '1.00', 'cash_account_id' => $this->own['account'], 'paid_on' => $day, 'reason' => 'x', 'idempotency_key' => 'k4']],
            'sales refund out of a foreign bank account' => ['post', 'sales.refunds.store', fn ($i) => ['source_payment_id' => $this->own['payment'], 'amount' => '1.00', 'cash_account_id' => $i['account'], 'paid_on' => $day, 'reason' => 'x', 'idempotency_key' => 'k5']],
            'link a foreign transaction to my customer' => ['post', 'sales.customers.link-transaction', fn ($i) => ['transaction_id' => $i['transaction']], ['party' => 'own']],
            'journal into a foreign GL account' => ['post', 'accounting.journals.store', fn ($i) => ['entry_date' => $day, 'memo' => 'x', 'lines' => [['account_id' => $i['glAccount'], 'debit' => '5.00'], ['account_id' => $this->own['glAccount'], 'credit' => '5.00']]]],
            'supplier payment for a foreign supplier' => ['post', 'purchases.payments.store', fn ($i) => ['party_id' => $i['supplierParty'], 'cash_account_id' => $this->own['account'], 'paid_on' => $day, 'idempotency_key' => 'k6', 'on_account' => '5.00']],
            'supplier payment from a foreign bank account' => ['post', 'purchases.payments.store', fn ($i) => ['party_id' => $this->own['supplierParty'], 'cash_account_id' => $i['account'], 'paid_on' => $day, 'idempotency_key' => 'k7', 'on_account' => '5.00']],
            'supplier payment settling a foreign bill' => ['post', 'purchases.payments.store', fn ($i) => ['party_id' => $this->own['supplierParty'], 'cash_account_id' => $this->own['account'], 'paid_on' => $day, 'idempotency_key' => 'k8', 'pay' => ['bill-'.$i['bill'] => '5.00']]],
            'bill for a foreign supplier' => ['post', 'purchases.bills.store', fn ($i) => ['party_id' => $i['supplierParty'], 'bill_date' => $day, 'currency' => 'ZAR', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '5.00']]]],
            'bill line to a foreign GL account' => ['post', 'purchases.bills.store', fn ($i) => ['party_id' => $this->own['supplierParty'], 'bill_date' => $day, 'currency' => 'ZAR', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '5.00', 'gl_account_id' => $i['glAccount']]]]],
            'payment run from a foreign bank account' => ['post', 'purchases.runs.store', fn ($i) => ['name' => 'x', 'due_by' => $day, 'paid_on' => $day, 'cash_account_id' => $i['account'], 'bill_ids' => [$this->own['bill']], 'idempotency_key' => 'k9']],
            'payment run of a foreign bill' => ['post', 'purchases.runs.store', fn ($i) => ['name' => 'x', 'due_by' => $day, 'paid_on' => $day, 'cash_account_id' => $this->own['account'], 'bill_ids' => [$i['bill']], 'idempotency_key' => 'k10']],
            'plan cell on a foreign GL account' => ['put', 'planning.budgets.update', fn ($i) => ['rows' => [['gl_account_id' => $i['glAccount'], 'months' => ['2026-01' => '5.00']]]], ['planBudget' => 'own']],
            'spend limit for a person outside the company' => ['post', 'controls.limits.store', fn ($i) => ['who' => 'user:'.$this->me->id + 999, 'currency' => 'ZAR', 'period' => 'daily', 'amount' => '5.00']],
            'role for a person outside the company' => ['put', 'controls.members.update', fn ($i) => ['role' => 'accountant'], ['member' => 'stranger']],
            'transaction into a foreign account' => ['post', 'transactions.store', fn ($i) => ['account_id' => $i['account'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
        ];
    }

    private function fire(string $method, string $routeName, array $body, array $params = [])
    {
        $files = array_filter($body, fn ($v) => $v instanceof \Illuminate\Http\UploadedFile);

        return $this->inCompany($this->me, $this->mine)->call(strtoupper($method), route($routeName, $params, false), array_diff_key($body, $files), [], $files);
    }

    public function test_a_foreign_id_in_any_money_form_is_refused_and_changes_nothing(): void
    {
        $refused = 0;
        $problems = [];

        foreach ($this->attempts() as $what => $case) {
            [$method, $routeName, $build] = $case;
            $params = [];
            foreach ($case[3] ?? [] as $name => $source) {
                $params[$name] = $source === 'own' ? $this->own[$name] : ($source === 'stranger' ? User::query()->where('id', '!=', $this->me->id)->orderBy('id')->value('id') : $source);
            }

            foreach ($this->foreign as $whose => $ids) {
                [$response, $changed] = $this->probe(fn () => $this->fire($method, $routeName, $build($ids), $params));
                $outcome = $this->classify($response);
                $refused++;

                if ($changed) {
                    $problems[] = "{$what} ({$whose}): CHANGED THE DATABASE, outcome {$outcome}";
                } elseif (! in_array($outcome, ['invalid', 'forbidden', 'not-found'], true)) {
                    $problems[] = "{$what} ({$whose}): not refused, outcome {$outcome}";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
        $this->assertGreaterThan(60, $refused);
    }

    public function test_the_same_forms_work_with_the_persons_own_ids_so_the_refusals_are_about_the_foreign_id(): void
    {
        $day = now()->toDateString();
        $own = $this->own;
        $worked = [];

        $controls = [
            'sales payment' => ['post', 'sales.payments.store', ['party_id' => $own['party'], 'cash_account_id' => $own['account'], 'amount' => '10.00', 'paid_on' => $day, 'idempotency_key' => 'c1']],
            'sales invoice' => ['post', 'sales.documents.store', ['kind' => 'tax_invoice', 'party_id' => $own['party'], 'issue_date' => $day, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00']]]],
            'journal' => ['post', 'accounting.journals.store', ['entry_date' => $day, 'memo' => 'x', 'lines' => [['account_id' => $own['glAccount'], 'debit' => '5.00'], ['account_id' => $own['glAccount'], 'credit' => '5.00']]]],
            'transaction' => ['post', 'transactions.store', ['account_id' => $own['account'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
            'supplier payment' => ['post', 'purchases.payments.store', ['party_id' => $own['supplierParty'], 'cash_account_id' => $own['account'], 'paid_on' => $day, 'idempotency_key' => 'c2', 'on_account' => '5.00']],
        ];

        foreach ($controls as $what => [$method, $routeName, $body]) {
            [$response, $changed] = $this->probe(fn () => $this->fire($method, $routeName, $body));
            $worked[$what] = $this->classify($response).($changed ? '+changed' : '');
        }

        // At least the plain ones must really write, otherwise the refusal tests above would be refusing a broken body.
        $this->assertStringContainsString('+changed', $worked['transaction'], json_encode($worked));
        $this->assertStringContainsString('+changed', $worked['sales payment'], json_encode($worked));
        $this->assertStringContainsString('+changed', $worked['sales invoice'], json_encode($worked));
    }

    public function test_the_live_preview_ignores_a_foreign_document_id_and_shows_nothing_of_it(): void
    {
        $day = now()->toDateString();

        foreach ($this->foreign as $whose => $ids) {
            $response = $this->inCompany($this->me, $this->mine)->postJson(route('sales.calculate', [], false), ['kind' => 'tax_invoice', 'document_id' => $ids['document'], 'party_id' => $this->own['party'], 'issue_date' => $day, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00']]]);

            $this->assertSame(200, $response->getStatusCode(), $whose);
            $this->assertDoesNotMatchRegularExpression('/Sentinel [a-z ]+ (theirs|personal|stranger)/', $response->getContent(), $whose.' leaked into the preview');
            $this->assertSame('ZAR 10.00', $response->json('lines.0.net'));
        }
    }
}
