<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;

class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team.
     *
     * A company that has a ledger here cannot be deleted: deleting the team would delete the
     * company's books, and nothing in this app deletes a ledger as a side effect.
     */
    public function delete(Team $team): void
    {
        if ($team->hasLedgerData()) {
            throw ValidationException::withMessages([
                'team' => "{$team->name} has a company ledger in Dot.Finance, and a company's books are never deleted along with its team. Nothing was deleted.",
            ]);
        }

        $team->purge();
    }
}
