<?php

namespace App\Support\Ledger;

use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The single definition of who may do what in a ledger. Every policy and gate asks
 * this class; nothing else interprets team roles.
 *
 * A person always has full control of their own personal ledger. In a company ledger
 * the role comes from the company team (InfoDot decides it; see SyncTeamsFromInfoDot):
 *
 *   owner, admin   everything: structure (accounts, categories, budgets) including
 *                  deleting them, opening-balance adjustments, entries, imports,
 *                  the company audit log.
 *   editor         records, edits and deletes transactions and recurring rules, makes
 *                  transfers, runs imports, and creates and edits accounts, categories
 *                  and budgets. Cannot delete those, adjust opening balances, or read the
 *                  audit log.
 *   viewer         reads everything (reports, forecast, reconciliation) and changes nothing.
 *                  Any role this app does not define is a viewer too.
 *
 * A person who does not belong to the ledger has no access at all (null).
 *
 * R-44 (Controls): those three levels are the BASELINE. On top of them every membership has a
 * finance role (owner, finance_manager, accountant, bookkeeper, approver, viewer): the baseline
 * InfoDot gives, optionally refined by a local grant (ledger_member_roles) that can never go
 * beyond what InfoDot allows. can(Capability) is the single source of truth; the canX() methods
 * below are kept exactly as they were and now ask it.
 */
final class LedgerAccess
{
    // ===== R-44 F3 CONTROLS: roles and capabilities (start) ==========================================
    // Everything between these markers is owned by the Controls module. can() is the single place
    // that answers "may this person do X in this ledger"; canPost / canManageChart / canClosePeriod
    // (added at the end of this class by the accounting module) must delegate to it.

    private function __construct(
        private readonly string $role,
        private readonly bool $personal,
        private readonly FinanceRole $financeRole,
        private readonly FinanceRole $baseline,
        private readonly bool $hasLocalRole,
        private readonly User $user,
        private readonly LedgerContext $context,
    ) {}

    /** Access to $context for $user, or null when they have none. */
    public static function for(User $user, ?LedgerContext $context): ?self
    {
        if ($context === null) {
            return null;
        }

        if ($context->isPersonal()) {
            return $context->userId === $user->id ? new self('owner', true, FinanceRole::Owner, FinanceRole::Owner, false, $user, $context) : null;
        }

        $team = $user->allTeams()->first(fn (Team $team) => $team->id === $context->teamId && $team->isCompany());

        if (! $team instanceof Team) {
            return null;
        }

        if ($user->ownsTeam($team)) {
            return new self('owner', false, FinanceRole::Owner, FinanceRole::Owner, false, $user, $context);
        }

        $role = $user->teamRole($team)?->key;
        $baseline = FinanceRole::baselineFor(in_array($role, ['admin', 'editor', 'viewer'], true) ? $role : 'viewer');

        // A local grant refines the baseline, but only inside what InfoDot allows for this person:
        // a grant above that ceiling (InfoDot lowered them since) is ignored, never honoured.
        $granted = LedgerMemberRole::query()->forContext($context)->where('member_user_id', $user->id)->first()?->role;
        $honoured = $granted !== null && $granted->canBeGrantedOn($baseline);
        $effective = $honoured ? $granted : $baseline;

        return new self($effective->legacyRole(), false, $effective, $baseline, $honoured, $user, $context);
    }

    /** May $user (default: the person this access is for) do $capability in this ledger? The single source. */
    public function can(Capability $capability, ?User $user = null): bool
    {
        if ($user !== null && $user->id !== $this->user->id) {
            return self::for($user, $this->context)?->can($capability) ?? false;
        }

        return $this->financeRole->grants($capability);
    }

    /** Convenience for code that has no access object: may $user (default: the signed-in person) do it in $context (default: the active ledger)? */
    public static function allows(Capability $capability, ?User $user = null, ?LedgerContext $context = null): bool
    {
        $user ??= auth()->user();

        return $user instanceof User && (self::for($user, $context ?? LedgerContext::current())?->can($capability) ?? false);
    }

    /** The finance role in force here: the local grant if there is one InfoDot allows, else the baseline. */
    public function financeRole(): FinanceRole
    {
        return $this->financeRole;
    }

    /** The finance role InfoDot's synced role gives with no local grant. */
    public function baselineRole(): FinanceRole
    {
        return $this->baseline;
    }

    public function hasLocalRole(): bool
    {
        return $this->hasLocalRole;
    }

    public function context(): LedgerContext
    {
        return $this->context;
    }

    // ===== R-44 F3 CONTROLS: roles and capabilities (end) ============================================

    /** Access in the active context. */
    public static function current(User $user): ?self
    {
        return self::for($user, LedgerContext::current());
    }

    /** Access to the ledger a record belongs to. */
    public static function forRow(User $user, Model $row): ?self
    {
        return self::for($user, $row->ledgerContext());
    }

    /** The original three-level role (owner, admin, editor, viewer) this maps to; kept for existing callers. */
    public function role(): string
    {
        return $this->role;
    }

    public function isPersonal(): bool
    {
        return $this->personal;
    }

    private function isManager(): bool
    {
        return in_array($this->financeRole, [FinanceRole::Owner, FinanceRole::FinanceManager], true);
    }

    private function isEditor(): bool
    {
        return in_array($this->financeRole, [FinanceRole::Accountant, FinanceRole::Bookkeeper], true);
    }

    /** What to call the role on screen: the finance role when one was granted here, else the familiar Owner/Admin/Editor/Viewer. */
    public function roleLabel(): string
    {
        return match (true) {
            $this->personal => 'Owner',
            $this->hasLocalRole => $this->financeRole->label(),
            default => ucfirst($this->role),
        };
    }

    public function canRead(): bool
    {
        return true;
    }

    /** Record, edit and delete transactions and recurring rules, make transfers, run imports. */
    public function canRecordEntries(): bool
    {
        return $this->can(Capability::RecordEntries);
    }

    /** Create and edit accounts, categories and budgets. */
    public function canEditStructure(): bool
    {
        return $this->can(Capability::EditStructure);
    }

    /** Delete accounts, categories and budgets. */
    public function canDestroyStructure(): bool
    {
        return $this->can(Capability::DestroyStructure);
    }

    public function canAdjustOpeningBalance(): bool
    {
        return $this->can(Capability::AdjustOpeningBalance);
    }

    /** Read the audit log: a person's own, or a company's (all members' actions). */
    public function canViewAudit(): bool
    {
        return $this->personal || $this->can(Capability::ViewAudit);
    }
}
