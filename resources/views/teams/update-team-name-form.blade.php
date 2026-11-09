<x-form-section submit="updateTeamName">
    <x-slot name="title">
        {{ __('Team Name') }}
    </x-slot>

    <x-slot name="description">
        {{ __('The team\'s name and owner information.') }}
    </x-slot>

    <x-slot name="form">
        <!-- Team Owner Information -->
        <div class="fx-field">
            <x-label value="{{ __('Team Owner') }}" />
            <div class="fx-ownerline">
                <x-fx.avatar :name="$team->owner->name" :large="true" />
                <div>
                    <div class="fx-cell-main">{{ $team->owner->name }}</div>
                    <div class="fx-cell-sub">{{ $team->owner->email }}</div>
                </div>
            </div>
        </div>

        <!-- Team Name -->
        <div class="fx-field">
            <x-label for="name" value="{{ __('Team Name') }}" />
            <x-input id="name" type="text" wire:model="state.name" :disabled="! Gate::check('update', $team)" />
            <x-input-error for="name" />
        </div>
    </x-slot>

    @if (Gate::check('update', $team))
        <x-slot name="actions">
            <x-action-message on="saved">
                {{ __('Saved.') }}
            </x-action-message>

            <x-button>
                {{ __('Save') }}
            </x-button>
        </x-slot>
    @endif
</x-form-section>
