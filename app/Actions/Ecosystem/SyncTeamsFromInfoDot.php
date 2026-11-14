<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use App\Services\Controls\LedgerRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies the companies InfoDot says a person belongs to, on every sign-in.
 *
 * A company here is a team linked to an InfoDot team by uuid. Rules:
 * - Only people InfoDot lists as owner or admin of a company create its team here;
 *   an ordinary member is attached once it exists, so a member can never become
 *   the local owner of someone else's company.
 * - Membership and role follow InfoDot (owner and admin become admin, editor stays
 *   editor, viewer and anything unknown or empty become viewer: read-only).
 * - Companies InfoDot no longer lists for the person are detached, so removing
 *   someone in InfoDot removes their access here at their next sign-in.
 * - The company InfoDot says is active becomes the person's current team.
 * - Personal teams are never linked or removed.
 * - R-50: a company InfoDot marks `entitled: false` for this platform is left exactly as it is here: not created,
 *   not renamed, no membership or role change, and it cannot be the person's current team. A company without
 *   the key (an InfoDot from before R-50) is treated as it always was.
 *
 * A company created here is a company ledger in Dot.Finance: the person's role in it decides
 * what they may do with the books (see LedgerAccess), and removing them in InfoDot removes
 * their access at their next sign-in.
 */
class SyncTeamsFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity  the redeem response
     */
    public function __invoke(User $user, array $identity): void
    {
        $named = collect($identity['teams'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['uuid'] ?? null) && ! ($t['personal'] ?? false))
            ->keyBy('uuid');

        // Present and anything but exactly true is a refusal; absent is not.
        $refused = $named->filter(fn ($t) => array_key_exists('entitled', $t) && $t['entitled'] !== true);
        $listed = $named->diffKeys($refused);

        DB::transaction(function () use ($user, $named, $refused, $listed, $identity) {
            foreach ($listed as $uuid => $info) {
                $this->apply($user, (string) $uuid, $info);
                $this->forgetCachedTeams($user);
            }

            // Revocation is about companies InfoDot no longer lists at all; a listed company that does not hold
            // this platform keeps whatever membership it has here.
            $this->revoke($user, $named->keys()->map(fn ($uuid) => (string) $uuid)->all());
            $this->forgetCachedTeams($user);

            $active = $identity['current_team_uuid'] ?? null;
            $team = $active && $listed->has($active) ? Team::where('infodot_team_uuid', $active)->first() : null;

            if ($team && $user->belongsToTeam($team)) {
                $user->switchTeam($team);
            } else {
                $this->leaveRefusedCompany($user, $refused->keys()->all(), $listed->keys()->all());
            }
        });
    }

    /**
     * A company that does not hold this platform cannot stay the person's current team: move them to an entitled
     * company they belong to here, else to their personal team.
     *
     * @param  list<int|string>  $refused
     * @param  list<int|string>  $entitled
     */
    private function leaveRefusedCompany(User $user, array $refused, array $entitled): void
    {
        $current = $user->current_team_id ? Team::find($user->current_team_id) : null;

        if (! $current || blank($current->infodot_team_uuid) || ! in_array($current->infodot_team_uuid, array_map('strval', $refused), true)) {
            return;
        }

        $next = $user->allTeams()->first(fn ($team) => in_array($team->infodot_team_uuid, array_map('strval', $entitled), true))
            ?? $user->personalTeam();

        if ($next) {
            $user->switchTeam($next);
            $this->forgetCachedTeams($user);
        }
    }

    /** Membership checks read relations cached on the model; reload after changing them. */
    private function forgetCachedTeams(User $user): void
    {
        $user->unsetRelation('teams')->unsetRelation('ownedTeams')->unsetRelation('currentTeam');
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function apply(User $user, string $uuid, array $info): void
    {
        $role = (string) ($info['role'] ?? '');
        $team = Team::where('infodot_team_uuid', $uuid)->first();

        if (! $team) {
            if (! in_array($role, ['owner', 'admin'], true)) {
                return;
            }

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => (string) ($info['name'] ?? 'Company'),
                'personal_team' => false,
                'infodot_team_uuid' => $uuid,
            ]);
        } elseif (filled($info['name'] ?? null) && $team->name !== $info['name']) {
            $team->forceFill(['name' => $info['name']])->save();
        }

        if ($team->user_id === $user->id) {
            return; // the local owner needs no membership row
        }

        $localRole = $this->localRole($role);

        if ($user->belongsToTeam($team)) {
            $team->users()->updateExistingPivot($user->id, ['role' => $localRole]);
            // R-44: InfoDot is the ceiling for local finance roles. The sync never raises one; it removes
            // any local grant that is now above what InfoDot allows for this person.
            app(LedgerRoles::class)->reconcile($team, $user);
        } else {
            $team->users()->attach($user->id, ['role' => $localRole]);
        }
    }

    /**
     * @param  list<string>  $keep
     */
    private function revoke(User $user, array $keep): void
    {
        $stale = $user->allTeams()->filter(fn ($team) => filled($team->infodot_team_uuid) && ! in_array($team->infodot_team_uuid, $keep, true));

        foreach ($stale as $team) {
            if ($team->user_id === $user->id) {
                // Cannot detach a team's owner; surface it instead of leaving it silent.
                Log::warning('InfoDot no longer lists a company this person owns locally.', ['team_id' => $team->id, 'user_id' => $user->id]);

                continue;
            }

            $team->users()->detach($user->id);
            app(LedgerRoles::class)->memberRemoved($team, $user); // R-44: their local role, limits and pending asks go with them

            if ($user->current_team_id === $team->id) {
                $user->forceFill(['current_team_id' => null])->save();
            }
        }
    }

    private function localRole(string $infodotRole): string
    {
        // Anything that is not clearly owner, admin or editor is a viewer: a role this app
        // has never heard of must not arrive with write access.
        return in_array($infodotRole, ['owner', 'admin'], true) ? 'admin' : ($infodotRole === 'editor' ? 'editor' : 'viewer');
    }
}
