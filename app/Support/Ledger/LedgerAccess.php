<?php

namespace App\Support\Ledger;

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
 *   anyone else    read-only for now. The editor and viewer roles arrive in the
 *                  roles change that stacks on this one.
 *
 * A person who does not belong to the ledger has no access at all (null).
 */
final class LedgerAccess
{
    private function __construct(private readonly string $role, private readonly bool $personal) {}

    /** Access to $context for $user, or null when they have none. */
    public static function for(User $user, ?LedgerContext $context): ?self
    {
        if ($context === null) {
            return null;
        }

        if ($context->isPersonal()) {
            return $context->userId === $user->id ? new self('owner', true) : null;
        }

        $team = $user->allTeams()->first(fn (Team $team) => $team->id === $context->teamId && $team->isCompany());

        if (! $team instanceof Team) {
            return null;
        }

        if ($user->ownsTeam($team)) {
            return new self('owner', false);
        }

        $role = $user->teamRole($team)?->key;

        return new self(in_array($role, ['admin', 'editor', 'viewer'], true) ? $role : 'viewer', false);
    }

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
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function canRead(): bool
    {
        return true;
    }

    /** Record, edit and delete transactions and recurring rules, make transfers, run imports. */
    public function canRecordEntries(): bool
    {
        return $this->isManager();
    }

    /** Create and edit accounts, categories and budgets. */
    public function canEditStructure(): bool
    {
        return $this->isManager();
    }

    /** Delete accounts, categories and budgets. */
    public function canDestroyStructure(): bool
    {
        return $this->isManager();
    }

    public function canAdjustOpeningBalance(): bool
    {
        return $this->isManager();
    }

    /** Read the audit log: a person's own, or a company's (all members' actions). */
    public function canViewAudit(): bool
    {
        return $this->personal || $this->isManager();
    }
}
