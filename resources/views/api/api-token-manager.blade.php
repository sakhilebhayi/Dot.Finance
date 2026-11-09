<div class="fx-settings-stack">
    <!-- Generate API Token -->
    <x-form-section submit="createApiToken">
        <x-slot name="title">
            {{ __('Create API Token') }}
        </x-slot>

        <x-slot name="description">
            {{ __('API tokens allow third-party services to authenticate with our application on your behalf.') }}
        </x-slot>

        <x-slot name="form">
            <!-- Token Name -->
            <div class="fx-field">
                <x-label for="name" value="{{ __('Token Name') }}" />
                <x-input id="name" type="text" wire:model="createApiTokenForm.name" autofocus />
                <x-input-error for="name" />
            </div>

            <!-- Token Permissions -->
            @if (Laravel\Jetstream\Jetstream::hasPermissions())
                <div class="fx-field">
                    <span class="fx-label">{{ __('Permissions') }}</span>

                    <div class="fx-row fx-row--2">
                        @foreach (Laravel\Jetstream\Jetstream::$permissions as $permission)
                            <label class="fx-check">
                                <x-checkbox wire:model="createApiTokenForm.permissions" :value="$permission"/>
                                <span>{{ $permission }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-slot>

        <x-slot name="actions">
            <x-action-message on="created">
                {{ __('Created.') }}
            </x-action-message>

            <x-button>
                {{ __('Create') }}
            </x-button>
        </x-slot>
    </x-form-section>

    @if ($this->user->tokens->isNotEmpty())
        <!-- Manage API Tokens -->
        <x-action-section>
            <x-slot name="title">
                {{ __('Manage API Tokens') }}
            </x-slot>

            <x-slot name="description">
                {{ __('You may delete any of your existing tokens if they are no longer needed.') }}
            </x-slot>

            <!-- API Token List -->
            <x-slot name="content">
                <ul class="fx-lines">
                    @foreach ($this->user->tokens->sortBy('name') as $token)
                        <li class="fx-line fx-line--split">
                            <x-fx.icon name="key-round" class="fx-line__icon" />
                            <div class="fx-line__main">
                                <p class="fx-line__title">{{ $token->name }}</p>
                                @if ($token->last_used_at)
                                    <p class="fx-line__sub">{{ __('Last used') }} {{ $token->last_used_at->diffForHumans() }}</p>
                                @endif
                            </div>

                            <div class="fx-line__side">
                                @if (Laravel\Jetstream\Jetstream::hasPermissions())
                                    <button type="button" class="fx-linkbtn" wire:click="manageApiTokenPermissions({{ $token->id }})">
                                        {{ __('Permissions') }}
                                    </button>
                                @endif

                                <button type="button" class="fx-linkbtn fx-linkbtn--danger" wire:click="confirmApiTokenDeletion({{ $token->id }})">
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    <!-- Token Value Modal -->
    <x-dialog-modal wire:model.live="displayingToken">
        <x-slot name="title">
            {{ __('API Token') }}
        </x-slot>

        <x-slot name="content">
            <p>
                {{ __('Please copy your new API token. For your security, it won\'t be shown again.') }}
            </p>

            <x-input x-ref="plaintextToken" type="text" readonly :value="$plainTextToken"
                class="fx-input--token"
                autofocus autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                @showing-token-modal.window="setTimeout(() => $refs.plaintextToken.select(), 250)"
            />
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$set('displayingToken', false)" wire:loading.attr="disabled">
                {{ __('Close') }}
            </x-secondary-button>
        </x-slot>
    </x-dialog-modal>

    <!-- API Token Permissions Modal -->
    <x-dialog-modal wire:model.live="managingApiTokenPermissions">
        <x-slot name="title">
            {{ __('API Token Permissions') }}
        </x-slot>

        <x-slot name="content">
            <div class="fx-row fx-row--2">
                @foreach (Laravel\Jetstream\Jetstream::$permissions as $permission)
                    <label class="fx-check">
                        <x-checkbox wire:model="updateApiTokenForm.permissions" :value="$permission"/>
                        <span>{{ $permission }}</span>
                    </label>
                @endforeach
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$set('managingApiTokenPermissions', false)" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button wire:click="updateApiToken" wire:loading.attr="disabled">
                {{ __('Save') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

    <!-- Delete Token Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingApiTokenDeletion">
        <x-slot name="title">
            {{ __('Delete API Token') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to delete this API token?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingApiTokenDeletion')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button wire:click="deleteApiToken" wire:loading.attr="disabled">
                {{ __('Delete') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>
