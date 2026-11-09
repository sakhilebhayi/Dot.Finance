<?php

namespace App\Actions\Jetstream;

use App\Models\Accounting\LedgerSetting;
use App\Models\Team;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(protected DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        // Posted journals are books of record: append-only, and never removed as a side effect of removing a person.
        // The database refuses it too (the owner keys restrict deletes); this says so in words.
        if (LedgerSetting::query()->forContext(LedgerContext::personal($user))->exists()) {
            throw ValidationException::withMessages(['password' => 'Your personal books have accounting switched on, and posted journal entries are kept as the record. Export what you need, then ask an administrator to retire the account.']);
        }

        // The same for every other table whose key restricts deleting a person (bank statements, tax records, reports, plans):
        // say so in words instead of letting the database refuse in the middle of the delete.
        if (Team::personHasRestrictedData($user)) {
            throw ValidationException::withMessages(['password' => 'Your personal books hold records that are kept (for example bank statements, tax records or reports), and they are not deleted along with a person. Export what you need, then ask an administrator to retire the account.']);
        }

        try {
            DB::transaction(function () use ($user) {
                $this->deleteTeams($user);
                $user->deleteProfilePhoto();
                $user->tokens->each->delete();
                $user->delete();
            });
        } catch (ValidationException $e) {
            // A company with a ledger cannot go with its owner. The delete-account form only shows
            // errors under the password field, so say it there instead of failing silently.
            throw ValidationException::withMessages(['password' => $e->errors()['team'] ?? [$e->getMessage()]]);
        }
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team) {
            $this->deletesTeams->delete($team);
        });
    }
}
