<?php

namespace Tests\Feature\Controls;

use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\BuildsControlsFixtures;
use Tests\TestCase;

/**
 * Every capability for every finance role, asserted against a written-out table (not derived from
 * the code under test), through the real LedgerAccess for real members of a real company.
 */
class CapabilityMatrixTest extends TestCase
{
    use BuildsControlsFixtures;
    use RefreshDatabase;

    private const EVERYTHING = [
        'view-finance', 'record-entries', 'manage-chart', 'post-journal', 'close-period', 'manage-parties', 'issue-invoice',
        'approve-invoice', 'record-payment', 'approve-payment', 'enter-bill', 'approve-bill', 'approve-expense', 'manage-tax',
        'prepare-tax-return', 'record-tax-submission', 'view-payroll-detail', 'manage-budgets', 'approve-budget', 'export-reports',
        'manage-members', 'view-audit', 'manage-approvals', 'run-reconciliation',
        'edit-structure', 'destroy-structure', 'adjust-opening-balance',
    ];

    /** @return array<string, list<string>> */
    private function expected(): array
    {
        return [
            'owner' => self::EVERYTHING,
            'manager' => self::EVERYTHING,
            'accountant' => [
                'view-finance', 'record-entries', 'edit-structure', 'manage-chart', 'post-journal', 'close-period', 'manage-parties',
                'issue-invoice', 'record-payment', 'enter-bill', 'manage-tax', 'prepare-tax-return', 'record-tax-submission',
                'view-payroll-detail', 'manage-budgets', 'export-reports', 'view-audit', 'run-reconciliation',
            ],
            'bookkeeper' => [
                'view-finance', 'record-entries', 'edit-structure', 'manage-parties', 'issue-invoice', 'record-payment',
                'enter-bill', 'manage-budgets', 'export-reports', 'run-reconciliation',
            ],
            'approver' => ['view-finance', 'approve-invoice', 'approve-payment', 'approve-bill', 'approve-expense', 'approve-budget'],
            'viewer' => ['view-finance'],
        ];
    }

    public function test_the_capability_list_is_exactly_the_specified_one(): void
    {
        $this->assertSame(self::EVERYTHING, Capability::values());
    }

    public function test_every_capability_for_every_role_matches_the_table(): void
    {
        $this->buildAcme();
        $checked = 0;

        foreach ($this->expected() as $person => $allowed) {
            $access = LedgerAccess::for($this->people[$person], $this->ctx());
            $this->assertNotNull($access, $person);

            foreach (Capability::cases() as $capability) {
                $this->assertSame(
                    in_array($capability->value, $allowed, true),
                    $access->can($capability),
                    "{$person} / {$capability->value}",
                );
                $checked++;
            }
        }

        $this->assertSame(6 * 27, $checked);
    }

    public function test_the_role_enum_agrees_with_the_table_too(): void
    {
        $byRole = ['owner' => FinanceRole::Owner, 'manager' => FinanceRole::FinanceManager, 'accountant' => FinanceRole::Accountant,
            'bookkeeper' => FinanceRole::Bookkeeper, 'approver' => FinanceRole::Approver, 'viewer' => FinanceRole::Viewer];

        foreach ($this->expected() as $person => $allowed) {
            $actual = array_map(fn (Capability $c) => $c->value, $byRole[$person]->capabilities());
            sort($actual);
            $want = $allowed;
            sort($want);
            $this->assertSame($want, $actual, $person);
        }
    }

    public function test_the_roles_resolve_as_designed_from_infodot_plus_local_grants(): void
    {
        $this->buildAcme();
        $want = ['owner' => FinanceRole::Owner, 'manager' => FinanceRole::FinanceManager, 'accountant' => FinanceRole::Accountant,
            'bookkeeper' => FinanceRole::Bookkeeper, 'approver' => FinanceRole::Approver, 'viewer' => FinanceRole::Viewer];

        foreach ($want as $person => $role) {
            $this->assertSame($role, LedgerAccess::for($this->people[$person], $this->ctx())->financeRole(), $person);
        }

        $this->assertSame(FinanceRole::Bookkeeper, LedgerAccess::for($this->people['accountant'], $this->ctx())->baselineRole());
        $this->assertSame(FinanceRole::Viewer, LedgerAccess::for($this->people['approver'], $this->ctx())->baselineRole());
    }

    public function test_a_personal_ledger_owner_can_do_everything(): void
    {
        $person = $this->user();
        $access = LedgerAccess::for($person, LedgerContext::personal($person));

        foreach (Capability::cases() as $capability) {
            $this->assertTrue($access->can($capability), $capability->value);
        }

        $this->assertSame(FinanceRole::Owner, $access->financeRole());
        $this->assertSame('Owner', $access->roleLabel());
    }

    public function test_the_legacy_methods_keep_their_exact_meaning_for_each_role(): void
    {
        $this->buildAcme();
        // [canRecordEntries, canEditStructure, canDestroyStructure, canAdjustOpeningBalance, canViewAudit]
        $expected = [
            'owner' => [true, true, true, true, true],
            'manager' => [true, true, true, true, true],
            'bookkeeper' => [true, true, false, false, false],
            'viewer' => [false, false, false, false, false],
            'approver' => [false, false, false, false, false],
        ];

        foreach ($expected as $person => $flags) {
            $a = LedgerAccess::for($this->people[$person], $this->ctx());
            $this->assertSame($flags, [$a->canRecordEntries(), $a->canEditStructure(), $a->canDestroyStructure(), $a->canAdjustOpeningBalance(), $a->canViewAudit()], $person);
            $this->assertTrue($a->canRead());
        }

        $this->assertSame('Admin', LedgerAccess::for($this->people['manager'], $this->ctx())->roleLabel(), 'no local grant: the familiar label');
        $this->assertSame('Editor', LedgerAccess::for($this->people['bookkeeper'], $this->ctx())->roleLabel());
        $this->assertSame('Accountant', LedgerAccess::for($this->people['accountant'], $this->ctx())->roleLabel(), 'a local grant shows the finance role');
        $this->assertSame('editor', LedgerAccess::for($this->people['accountant'], $this->ctx())->role());
        $this->assertSame('viewer', LedgerAccess::for($this->people['approver'], $this->ctx())->role());
    }

    public function test_can_can_be_asked_about_another_person_in_the_same_ledger(): void
    {
        $this->buildAcme();
        $mine = LedgerAccess::for($this->people['viewer'], $this->ctx());

        $this->assertFalse($mine->can(Capability::ApproveBill));
        $this->assertTrue($mine->can(Capability::ApproveBill, $this->people['approver']));
        $this->assertFalse($mine->can(Capability::ApproveBill, $this->user()), 'a stranger holds nothing here');
        $this->assertTrue(LedgerAccess::allows(Capability::ApproveBill, $this->people['approver'], $this->ctx()));
        $this->assertFalse(LedgerAccess::allows(Capability::ApproveBill, $this->people['viewer'], $this->ctx()));
    }

    public function test_a_person_outside_the_company_has_no_access_at_all(): void
    {
        $this->buildAcme();
        $this->assertNull(LedgerAccess::for($this->user(), $this->ctx()));
        $this->assertNull(LedgerAccess::for($this->people['owner'], $this->ctx($this->company($this->user(), 'Other Ltd'))));
    }

    public function test_every_capability_is_registered_as_a_gate(): void
    {
        foreach (Capability::cases() as $capability) {
            $this->assertTrue(Gate::has($capability->value), $capability->value);
        }
    }
}
