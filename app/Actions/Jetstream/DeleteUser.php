<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
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
