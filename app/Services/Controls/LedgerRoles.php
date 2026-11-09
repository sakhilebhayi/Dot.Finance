<?php

namespace App\Services\Controls;

use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Controls\SpendLimit;
use App\Models\Team;
use App\Models\User;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is in a ledger and which finance role each person holds.
 *
 * The baseline always comes from InfoDot (the synced team role); a local grant refines it for
 * this ledger only. Three rules keep that safe, each with a test:
 *
 *  - a grant can never go beyond what InfoDot allows for that person (FinanceRole::grantable),
 *    so neither the InfoDot sync nor a manager can turn an InfoDot viewer into a writer;
 *  - a grant can never exceed the grantor's own role, and a person cannot change the role of
 *    someone above them;
 *  - the sync only ever lowers: when InfoDot lowers or removes a person, grants above the new
 *    ceiling are removed (reconcile / memberRemoved), and LedgerAccess ignores any it still finds.
 *
 * Personal ledgers have one member, the person, who is the owner. They have no grants.
 */
final class LedgerRoles
{
    /**
     * Everyone in the ledger with the role they hold.
     *
     * @return Collection<int, array{user: User, access: LedgerAccess, infodot: string, grant: ?LedgerMemberRole}>
     */
    public function members(LedgerContext $context): Collection
    {
        if ($context->isPersonal()) {
            $user = User::findOrFail($context->userId);

            return collect([[
                'user' => $user, 'access' => LedgerAccess::for($user, $context), 'infodot' => 'owner', 'grant' => null,
            ]]);
        }

        $team = $this->team($context);
        $grants = LedgerMemberRole::query()->forContext($context)->with('grantor:id,name')->get()->keyBy('member_user_id');

        return $team->allUsers()->unique('id')->map(function (User $user) use ($context, $grants, $team) {
            $access = LedgerAccess::for($user->fresh(), $context);

            return [
                'user' => $user,
                'access' => $access,
                'infodot' => $user->id === $team->user_id ? 'owner' : (string) ($user->membership?->role ?? 'viewer'),
                'grant' => $grants->get($user->id),
            ];
        })->filter(fn (array $row) => $row['access'] !== null)->sortBy(fn (array $row) => [-$row['access']->financeRole()->rank(), $row['user']->name])->values();
    }

    public function memberCount(LedgerContext $context): int
    {
        return $context->isPersonal() ? 1 : $this->team($context)->allUsers()->unique('id')->count();
    }

    /**
     * Give $member the finance role $role in this ledger, or (null) go back to what InfoDot gives.
     *
     * @throws ControlsViolation
     */
    public function assign(User $actor, LedgerContext $context, User $member, ?FinanceRole $role): ?LedgerMemberRole
    {
        if ($context->isPersonal()) {
            throw new ControlsViolation('personal_ledger', 'A personal ledger has one member, its owner. Roles apply to company ledgers.');
        }

        $by = LedgerAccess::for($actor, $context) ?? throw new ControlsViolation('no_access', 'You do not belong to this ledger.');

        if (! $by->can(Capability::ManageMembers)) {
            throw new ControlsViolation('missing_capability', 'Only an owner or finance manager can change roles.');
        }

        $team = $this->team($context);
        $target = LedgerAccess::for($member, $context) ?? throw new ControlsViolation('not_a_member', 'That person does not belong to this company.');

        if ($member->ownsTeam($team)) {
            throw new ControlsViolation('owner_role', "The company's owner always holds the owner role here.");
        }

        if ($target->financeRole()->rank() > $by->financeRole()->rank()) {
            throw new ControlsViolation('role_too_low', "You cannot change the role of someone who outranks you ({$target->financeRole()->label()}).");
        }

        if ($role !== null) {
            if (! $role->canBeGrantedOn($target->baselineRole())) {
                throw new ControlsViolation('above_infodot_role', "InfoDot gives this person {$target->baselineRole()->label()} access, and a local role cannot go beyond what InfoDot allows. Change their role in InfoDot first.");
            }

            if ($role->rank() > $by->financeRole()->rank()) {
                throw new ControlsViolation('above_own_role', "You cannot give a role above your own ({$by->financeRole()->label()}).");
            }
        }

        return DB::transaction(function () use ($actor, $context, $member, $role, $target) {
            $existing = LedgerMemberRole::query()->forContext($context)->where('member_user_id', $member->id)->lockForUpdate()->first();

            if ($role === null) {
                if ($existing !== null) {
                    $this->audit($context, 'revoked', $existing, $member, ['role' => ['before' => $existing->role->value, 'after' => null]], $actor->id);
                    $existing->delete();
                }

                return null;
            }

            if ($existing !== null && $existing->role === $role) {
                return $existing; // already so: nothing to change, nothing to log
            }

            $before = $existing?->role?->value;
            $grant = $existing ?? new LedgerMemberRole($context->ownerAttributes() + ['member_user_id' => $member->id]);
            $grant->fill(['role' => $role, 'granted_by' => $actor->id, 'granted_at' => now()])->save();

            $this->audit($context, $existing === null ? 'granted' : 'changed', $grant, $member, ['role' => ['before' => $before ?? $target->baselineRole()->value, 'after' => $role->value]], $actor->id);

            return $grant;
        });
    }

    /**
     * InfoDot changed this person's role: remove any local grant now above what InfoDot allows.
     * Called by the sync after it updates the team role; never raises anything.
     */
    public function reconcile(Team $team, User $user): void
    {
        $context = LedgerContext::company($team);
        $grant = LedgerMemberRole::query()->forContext($context)->where('member_user_id', $user->id)->first();

        if ($grant === null) {
            return;
        }

        $role = $team->users()->wherePivot('user_id', $user->id)->first()?->membership?->role;
        $baseline = FinanceRole::baselineFor(in_array($role, ['admin', 'editor', 'viewer'], true) ? $role : 'viewer');

        if ($grant->role->canBeGrantedOn($baseline)) {
            return;
        }

        $this->audit($context, 'revoked', $grant, $user, ['role' => ['before' => $grant->role->value, 'after' => null]], null, 'InfoDot role lowered');
        $grant->delete();
    }

    /**
     * A person left the company or InfoDot removed them. They keep nothing here: their local
     * role and spend limits go, and requests they had asked for that are still waiting are
     * cancelled (they can no longer be approved on behalf of someone who is not here).
     * Anything they could approve is already out of their hands: with no access they hold no capability.
     */
    public function memberRemoved(Team $team, User $user): void
    {
        if (! $team->isCompany()) {
            return;
        }

        $context = LedgerContext::company($team);

        DB::transaction(function () use ($context, $user) {
            $grant = LedgerMemberRole::query()->forContext($context)->where('member_user_id', $user->id)->first();

            if ($grant !== null) {
                $this->audit($context, 'revoked', $grant, $user, ['role' => ['before' => $grant->role->value, 'after' => null]], null, 'left the company');
                $grant->delete();
            }

            SpendLimit::query()->forContext($context)->where('member_user_id', $user->id)->get()->each->delete();

            app(ApprovalService::class)->cancelPendingBy($context, $user, 'The person who asked for this approval left the company.');
        });
    }

    private function team(LedgerContext $context): Team
    {
        return $context->team() ?? throw new ControlsViolation('invalid', 'That company does not exist.');
    }

    /** @param  array<string, array<string, mixed>>  $changes */
    private function audit(LedgerContext $context, string $action, LedgerMemberRole $grant, User $member, array $changes, ?int $actorId, ?string $note = null): void
    {
        FinanceAudit::record(
            $context->ownerAttributes(), $action, 'member_role', (int) $grant->getKey(),
            $member->name.': '.($grant->role?->label() ?? 'role').($note ? " ({$note})" : ''),
            $changes, 'member:'.$member->id, null, $actorId,
        );
    }
}
