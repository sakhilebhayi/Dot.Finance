<x-form-section submit="createTeam">
    <x-slot name="title">
        {{ __('Team Details') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Create a new team to collaborate with others on projects.') }}
    </x-slot>

    <x-slot name="form">
        <div class="fx-field">
            <x-label value="{{ __('Team Owner') }}" />
            <div class="fx-ownerline">
                <x-fx.avatar :name="$this->user->name" :large="true" />
                <div>
                    <div class="fx-cell-main">{{ $this->user->name }}</div>
                    <div class="fx-cell-sub">{{ $this->user->email }}</div>
                </div>
            </div>
        </div>

        <div class="fx-field">
            <x-label for="name" value="{{ __('Team Name') }}" />
            <x-input id="name" type="text" wire:model="state.name" autofocus />
            <x-input-error for="name" />
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-button>
            {{ __('Create') }}
        </x-button>
    </x-slot>
</x-form-section>
