<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\Bank\BankMatch;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankRule;
use App\Models\Bank\BankStatementLine;
use App\Services\Bank\MatchingService;
use App\Services\Bank\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBankFixtures;
use Tests\Concerns\BuildsControlsFixtures;
use Tests\TestCase;

/** Who may do what in the bank module, for real members of a real company at each finance role. */
class BankAccessTest extends TestCase
{
    use BuildsBankFixtures;
    use BuildsControlsFixtures;
    use RefreshDatabase;

    private Account $account;

    private BankReconciliation $draft;

    private BankReconciliation $done;

    private BankRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAcme();
        $this->account = Account::factory()->forCompany($this->acme)->create(['name' => 'Acme cheque', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
        $savings = Account::factory()->forCompany($this->acme)->create(['name' => 'Acme savings', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);

        // one completed session on the savings account, and an open one on the cheque account
        $this->ledgerRow($savings, '2026-10-03', 10_000, 'Deposit');
        $this->bankLine($savings, '2026-10-03', 10_000, 'Deposit');
        $this->done = $this->openSession($this->people['owner'], $savings, '2026-10-31', 10_000);
        $this->inLedger($savings, function () {
            $matching = app(MatchingService::class);
            $matching->accept($this->done, $matching->suggest($this->done)['proposals'], $this->people['owner']);
            app(ReconciliationService::class)->complete($this->done, $this->people['owner']);
        });

        $this->bankLine($this->account, '2026-10-05', -500, 'Open line');
        $this->draft = $this->openSession($this->people['owner'], $this->account, '2026-10-31', -500);
        $this->rule = BankRule::create($this->ctx()->ownerAttributes() + ['name' => 'R', 'pattern' => 'x']);
    }

    public function test_everyone_in_the_ledger_can_read_the_bank_pages(): void
    {
        $pages = [
            route('bank.statements.index'), route('bank.reconciliation.index'), route('bank.reconciliation.show', $this->draft),
            route('bank.reconciliation.show', $this->done), route('bank.reconciliation.report', $this->done), route('bank.rules.index'),
        ];

        foreach ($this->people as $role => $person) {
            foreach ($pages as $url) {
                $this->inCompany($person, $this->acme)->get($url)->assertOk("{$role} should read {$url}");
            }
        }
    }

    public function test_only_people_who_may_run_reconciliation_reach_the_work_pages(): void
    {
        $allowed = ['owner', 'manager', 'accountant', 'bookkeeper'];

        foreach ($this->people as $role => $person) {
            foreach ([route('bank.import.create'), route('bank.rules.edit', $this->rule)] as $url) {
                $status = $this->inCompany($person, $this->acme)->get($url)->getStatusCode();
                in_array($role, $allowed, true) ? $this->assertSame(200, $status, "{$role} {$url}") : $this->assertSame(403, $status, "{$role} {$url}");
            }
        }
    }

    public function test_write_routes_follow_the_capability_matrix(): void
    {
        $allowed = ['owner', 'manager', 'accountant', 'bookkeeper'];
        $line = BankStatementLine::query()->forContext($this->ctx())->where('account_id', $this->account->id)->firstOrFail();

        $writes = [
            'start' => ['post', route('bank.reconciliation.start'), ['account_id' => $this->account->id]],
            'accept' => ['post', route('bank.reconciliation.accept', $this->draft), ['mode' => 'auto']],
            'match' => ['post', route('bank.reconciliation.match', $this->draft), ['line_ids' => [$line->id], 'entry_ids' => [1]]],
            'entries' => ['post', route('bank.reconciliation.entries', $this->draft), ['mode' => 'entry', 'line_ids' => [$line->id]]],
            'complete' => ['post', route('bank.reconciliation.complete', $this->draft), []],
            'rule' => ['post', route('bank.rules.store'), ['name' => 'n', 'pattern' => 'p', 'match_field' => 'any', 'operator' => 'contains', 'direction' => 'any']],
            'upload' => ['post', route('bank.import.upload'), ['account_id' => $this->account->id]],
            'discard' => ['delete', route('bank.statements.destroy', $line->bank_statement_id), []],
        ];

        foreach ($this->people as $role => $person) {
            foreach ($writes as $what => [$method, $url, $body]) {
                $status = $this->inCompany($person, $this->acme)->{$method}($url, $body)->getStatusCode();
                in_array($role, $allowed, true)
                    ? $this->assertNotSame(403, $status, "{$role} should be allowed to {$what}")
                    : $this->assertSame(403, $status, "{$role} must not {$what}");
            }
        }
    }

    public function test_reopening_needs_the_accountant_level_capability_not_just_reconciliation(): void
    {
        foreach (['bookkeeper', 'approver', 'viewer'] as $role) {
            $this->inCompany($this->people[$role], $this->acme)->post(route('bank.reconciliation.reopen', $this->done), ['reason' => 'Trying to reopen it'])->assertForbidden();
        }
        $this->assertSame('completed', $this->done->fresh()->status);

        $this->inCompany($this->people['accountant'], $this->acme)->post(route('bank.reconciliation.reopen', $this->done), ['reason' => 'Accountant reopens it'])->assertSessionHasNoErrors();
        $this->assertSame('draft', $this->done->fresh()->status);
    }

    public function test_a_bookkeeper_cannot_accept_a_difference_even_by_posting_the_field(): void
    {
        $this->inCompany($this->people['bookkeeper'], $this->acme)
            ->post(route('bank.reconciliation.complete', $this->draft), ['accept_variance' => 1, 'variance_reason' => 'I will accept it myself'])
            ->assertSessionHasErrors('bank');

        $this->assertSame('draft', $this->draft->fresh()->status);
        $this->assertDatabaseCount('bank_adjustments', 0);
    }

    public function test_the_csv_export_needs_export_reports(): void
    {
        foreach (['approver', 'viewer'] as $role) {
            $this->inCompany($this->people[$role], $this->acme)->get(route('bank.reconciliation.csv', $this->done))->assertForbidden();
        }
        foreach (['owner', 'manager', 'accountant', 'bookkeeper'] as $role) {
            $this->inCompany($this->people[$role], $this->acme)->get(route('bank.reconciliation.csv', $this->done))->assertOk();
        }
    }

    public function test_a_session_edit_by_a_viewer_through_the_service_is_not_possible_either(): void
    {
        $this->inCompany($this->people['viewer'], $this->acme)->post(route('bank.reconciliation.unmatch', [$this->done, BankMatch::query()->forContext($this->ctx())->first()]))->assertForbidden();
        $this->assertSame(1, BankMatch::query()->forContext($this->ctx())->where('state', 'active')->count());
    }
}
