<x-app-layout title="Team settings">
    <x-fx.page-head title="Team settings" :sub="$team->name" />

    <div class="fx-settings-stack">
        @livewire('teams.update-team-name-form', ['team' => $team])

        @livewire('teams.team-member-manager', ['team' => $team])

        @if (Gate::check('delete', $team) && ! $team->personal_team)
            @livewire('teams.delete-team-form', ['team' => $team])
        @endif
    </div>
</x-app-layout>
