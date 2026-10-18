<?php

namespace App\Providers;

use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\CreateTeam;
use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamName;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();

        Jetstream::createTeamsUsing(CreateTeam::class);
        Jetstream::updateTeamNamesUsing(UpdateTeamName::class);
        Jetstream::addTeamMembersUsing(AddTeamMember::class);
        Jetstream::inviteTeamMembersUsing(InviteTeamMember::class);
        Jetstream::removeTeamMembersUsing(RemoveTeamMember::class);
        Jetstream::deleteTeamsUsing(DeleteTeam::class);
        Jetstream::deleteUsersUsing(DeleteUser::class);
    }

    /**
     * Configure the roles and permissions that are available within the application.
     */
    protected function configurePermissions(): void
    {
        Jetstream::defaultApiTokenPermissions(['read']);

        // How these map to the finance ledger is defined once, in App\Support\Ledger\LedgerAccess.
        // The company owner has every ability without a role. InfoDot decides each person's role
        // (see SyncTeamsFromInfoDot): owner and admin become admin, editor stays editor, anything
        // else (viewer, or a role this app has never heard of) becomes viewer.
        Jetstream::role('admin', 'Administrator', [
            'create',
            'read',
            'update',
            'delete',
        ])->description('Everything in the company ledger: accounts, categories and budgets including deleting them, opening-balance adjustments, entries, imports, and the audit log.');

        Jetstream::role('editor', 'Editor', [
            'read',
            'create',
            'update',
        ])->description('Records, edits and deletes transactions and recurring items, makes transfers, runs imports, and creates and edits accounts, categories and budgets. Cannot delete accounts, categories or budgets, adjust opening balances, or read the audit log.');

        Jetstream::role('viewer', 'Viewer', [
            'read',
        ])->description('Reads everything, including reports and the forecast. Cannot change anything.');
    }
}
