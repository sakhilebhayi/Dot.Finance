<?php

namespace App\Support\Ledger;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The company whose books a request opens, the one rule shared by the entitlement check and the header's platform
 * switcher, so both ask InfoDot about the same company.
 *
 * A {team} in the address (Jetstream's team pages) wins when the person belongs to it; otherwise the active ledger
 * (LedgerContextManager) decides. The personal ledger is not a company: it gives null, and so does a team that is not
 * a company. A caller reads `infodot_team_uuid` from the result; a company InfoDot does not know has none.
 */
class EntitledCompany
{
    public function __construct(private readonly LedgerContextManager $contexts) {}

    /** The company whose books this request opens, or null for the personal ledger. */
    public function for(Request $request, User $user): ?Team
    {
        $named = $request->route('team');

        if (is_scalar($named)) {
            $named = $user->allTeams()->firstWhere('id', (int) $named);
        }

        if ($named instanceof Team && $user->belongsToTeam($named)) {
            return $named->isCompany() ? $named : null;
        }

        $context = $this->contexts->current();

        if ($context === null || ! $context->isCompany()) {
            return null;
        }

        $team = Team::find($context->teamId);

        return $team instanceof Team && $team->isCompany() ? $team : null;
    }
}
