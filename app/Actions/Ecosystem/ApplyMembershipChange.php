<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use App\Services\Controls\LedgerRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a membership change InfoDot has just made, so access ends in seconds instead of at the person's next
 * sign-in (which still catches anything this misses). The same three events the other Dot platforms receive:
 *
 *  - member.removed: the person is detached from the linked company; their local role grant, spending limits and
 *    pending approval requests go with them (LedgerRoles::memberRemoved) and, if it was their current team, it is
 *    cleared. A company's local owner is never detached.
 *  - member.role_changed: their role is set with the same mapping as sign-in (owner/admin = admin, editor, else
 *    viewer) and any local finance role above what InfoDot now allows is dropped (LedgerRoles::reconcile).
 *  - team.deleted: every member except the local owner is detached. The link to the InfoDot company is KEPT and
 *    nothing is deleted: the books of a company are records the owner must be able to keep and export, so deleting a
 *    company in InfoDot ends everyone else's access but never destroys or hides the ledger from its owner.
 *
 * Unknown companies and people are ignored without a word, so a caller learns nothing about who exists here. Every
 * event is idempotent.
 */
class ApplyMembershipChange
{
    public function __construct(private readonly LedgerRoles $roles) {}

    /**
     * @param  array{event: string, team_uuid: string, email?: string|null, role?: string|null}  $change
     */
    public function __invoke(array $change): void
    {
        $team = Team::where('infodot_team_uuid', $change['team_uuid'])->first();

        if (! $team) {
            return;
        }

        DB::transaction(function () use ($team, $change) {
            match ($change['event']) {
                'member.removed' => $this->remove($team, $this->person($change)),
                'member.role_changed' => $this->changeRole($team, $this->person($change), (string) ($change['role'] ?? '')),
                'team.deleted' => $this->detachEveryone($team),
                default => null,
            };
        });
    }

    /** @param  array<string, mixed>  $change */
    private function person(array $change): ?User
    {
        return filled($change['email'] ?? null) ? User::whereRaw('lower(email) = ?', [strtolower((string) $change['email'])])->first() : null;
    }

    private function remove(Team $team, ?User $user): void
    {
        if (! $user) {
            return;
        }

        if ($team->user_id === $user->id) {
            Log::warning('InfoDot removed the local owner of a company; left in place.', ['team_id' => $team->id, 'user_id' => $user->id]);

            return;
        }

        if ($team->users()->where('users.id', $user->id)->exists()) {
            $team->users()->detach($user->id);
            $this->roles->memberRemoved($team, $user);
        }

        if ($user->current_team_id === $team->id) {
            $user->forceFill(['current_team_id' => null])->save();
        }
    }

    private function changeRole(Team $team, ?User $user, string $infodotRole): void
    {
        if (! $user || $team->user_id === $user->id || ! $team->users()->where('users.id', $user->id)->exists()) {
            return;
        }

        $team->users()->updateExistingPivot($user->id, ['role' => $this->localRole($infodotRole)]);
        $this->roles->reconcile($team, $user->fresh());
    }

    private function detachEveryone(Team $team): void
    {
        foreach ($team->users()->get() as $member) {
            if ($member instanceof User) {
                $this->remove($team, $member);
            }
        }
    }

    /** The same mapping as the sign-in sync: anything not clearly owner, admin or editor is read-only. */
    private function localRole(string $infodotRole): string
    {
        return in_array($infodotRole, ['owner', 'admin'], true) ? 'admin' : ($infodotRole === 'editor' ? 'editor' : 'viewer');
    }
}
