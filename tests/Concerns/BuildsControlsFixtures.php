<?php

namespace Tests\Concerns;

use App\Enums\FinanceRole;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ControlsBill;

/**
 * A company with one person at each finance role, and a stand-in approvable record.
 *
 *   owner        the company's local owner
 *   manager      InfoDot admin                       -> finance manager
 *   accountant   InfoDot editor + local grant        -> accountant
 *   bookkeeper   InfoDot editor, no grant            -> bookkeeper
 *   approver     InfoDot viewer + local grant        -> approver
 *   viewer       InfoDot viewer, no grant            -> viewer
 */
trait BuildsControlsFixtures
{
    use BuildsCompanyLedgers;

    /** @var array<string, User> */
    protected array $people = [];

    protected Team $acme;

    protected function createBillsTable(): void
    {
        if (! Schema::hasTable('controls_test_bills')) {
            Schema::create('controls_test_bills', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable();
                $table->foreignId('team_id')->nullable();
                $table->bigInteger('amount_cents')->default(0);
                $table->string('currency', 3)->nullable();
                $table->string('state')->default('draft');
                $table->timestamps();
            });
        }

        ControlsBill::$decided = [];
        ControlsBill::$kind = 'bill';
    }

    protected function buildAcme(string $name = 'Acme (Pty) Ltd'): void
    {
        $this->createBillsTable();

        $owner = $this->user();
        $this->acme = $this->company($owner, $name);
        $this->people = [
            'owner' => $owner,
            'manager' => $this->memberOf($this->acme, 'admin'),
            'accountant' => $this->memberOf($this->acme, 'editor'),
            'bookkeeper' => $this->memberOf($this->acme, 'editor'),
            'approver' => $this->memberOf($this->acme, 'viewer'),
            'viewer' => $this->memberOf($this->acme, 'viewer'),
        ];

        $this->grant($this->acme, $this->people['accountant'], FinanceRole::Accountant);
        $this->grant($this->acme, $this->people['approver'], FinanceRole::Approver);
    }

    /** Write a local grant directly (the fixture; the service's rules are tested separately). */
    protected function grant(Team $company, User $member, FinanceRole $role, ?User $by = null): LedgerMemberRole
    {
        return LedgerMemberRole::create([
            'team_id' => $company->id, 'member_user_id' => $member->id, 'role' => $role->value,
            'granted_by' => ($by ?? $company->owner)->id, 'granted_at' => now(),
        ]);
    }

    protected function ctx(?Team $company = null): LedgerContext
    {
        return LedgerContext::company($company ?? $this->acme);
    }

    protected function bill(int $cents = 1_000_000, string $currency = 'ZAR', ?Team $company = null): ControlsBill
    {
        return ControlsBill::create(['team_id' => ($company ?? $this->acme)->id, 'amount_cents' => $cents, 'currency' => $currency]);
    }
}
