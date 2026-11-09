<div class="fx-settings-stack">
    @if (Gate::check('addTeamMember', $team))
        <!-- Add Team Member -->
        <x-form-section submit="addTeamMember">
            <x-slot name="title">
                {{ __('Add Team Member') }}
            </x-slot>

            <x-slot name="description">
                {{ __('Add a new team member to your team, allowing them to collaborate with you.') }}
            </x-slot>

            <x-slot name="form">
                <p class="fx-lede">
                    {{ __('Please provide the email address of the person you would like to add to this team.') }}
                </p>

                <!-- Member Email -->
                <div class="fx-field">
                    <x-label for="email" value="{{ __('Email') }}" />
                    <x-input id="email" type="email" wire:model="addTeamMemberForm.email" />
                    <x-input-error for="email" />
                </div>

                <!-- Role -->
                @if (count($this->roles) > 0)
                    <div class="fx-field">
                        <span class="fx-label" id="add-member-role-label">{{ __('Role') }}</span>
                        <x-input-error for="role" />

                        <div class="fx-roles" role="group" aria-labelledby="add-member-role-label">
                            @foreach ($this->roles as $role)
                                @php $chosen = ($addTeamMemberForm['role'] ?? null) == $role->key; @endphp
                                <button type="button" class="fx-roleopt" aria-pressed="{{ $chosen ? 'true' : 'false' }}"
                                                wire:click="$set('addTeamMemberForm.role', '{{ $role->key }}')">
                                    <span class="fx-roleopt__name">{{ $role->name }}@if ($chosen)<x-fx.icon name="check" />@endif</span>
                                    <span class="fx-roleopt__desc">{{ $role->description }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-slot>

            <x-slot name="actions">
                <x-action-message on="saved">
                    {{ __('Added.') }}
                </x-action-message>

                <x-button>
                    {{ __('Add') }}
                </x-button>
            </x-slot>
        </x-form-section>
    @endif

    @if ($team->teamInvitations->isNotEmpty() && Gate::check('addTeamMember', $team))
        <!-- Team Member Invitations -->
        <x-action-section>
            <x-slot name="title">
                {{ __('Pending Team Invitations') }}
            </x-slot>

            <x-slot name="description">
                {{ __('These people have been invited to your team and have been sent an invitation email. They may join the team by accepting the email invitation.') }}
            </x-slot>

            <x-slot name="content">
                <ul class="fx-lines">
                    @foreach ($team->teamInvitations as $invitation)
                        <li class="fx-line fx-line--split">
                            <div class="fx-line__main"><p class="fx-line__title">{{ $invitation->email }}</p></div>

                            @if (Gate::check('removeTeamMember', $team))
                                <!-- Cancel Team Invitation -->
                                <div class="fx-line__side">
                                    <button type="button" class="fx-linkbtn fx-linkbtn--danger" wire:click="cancelTeamInvitation({{ $invitation->id }})">
                                        {{ __('Cancel') }}
                                    </button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    @if ($team->users->isNotEmpty())
        <!-- Manage Team Members -->
        <x-action-section>
            <x-slot name="title">
                {{ __('Team Members') }}
            </x-slot>

            <x-slot name="description">
                {{ __('All of the people that are part of this team.') }}
            </x-slot>

            <!-- Team Member List -->
            <x-slot name="content">
                <ul class="fx-lines">
                    @foreach ($team->users->sortBy('name') as $user)
                        <li class="fx-line fx-line--split">
                            <x-fx.avatar :name="$user->name" />
                            <div class="fx-line__main"><p class="fx-line__title">{{ $user->name }}</p></div>

                            <div class="fx-line__side">
                                <!-- Manage Team Member Role -->
                                @if (Gate::check('updateTeamMember', $team) && Laravel\Jetstream\Jetstream::hasRoles())
                                    <button type="button" class="fx-linkbtn" wire:click="manageRole('{{ $user->id }}')">
                                        {{ Laravel\Jetstream\Jetstream::findRole($user->membership->role)->name }}
                                    </button>
                                @elseif (Laravel\Jetstream\Jetstream::hasRoles())
                                    <x-fx.pill kind="plain">{{ Laravel\Jetstream\Jetstream::findRole($user->membership->role)->name }}</x-fx.pill>
                                @endif

                                <!-- Leave Team -->
                                @if ($this->user->id === $user->id)
                                    <button type="button" class="fx-linkbtn fx-linkbtn--danger" wire:click="$toggle('confirmingLeavingTeam')">
                                        {{ __('Leave') }}
                                    </button>

                                <!-- Remove Team Member -->
                                @elseif (Gate::check('removeTeamMember', $team))
                                    <button type="button" class="fx-linkbtn fx-linkbtn--danger" wire:click="confirmTeamMemberRemoval('{{ $user->id }}')">
                                        {{ __('Remove') }}
                                    </button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    <!-- Role Management Modal -->
    <x-dialog-modal wire:model.live="currentlyManagingRole">
        <x-slot name="title">
            {{ __('Manage Role') }}
        </x-slot>

        <x-slot name="content">
            <div class="fx-roles" role="group" aria-label="{{ __('Role') }}">
                @foreach ($this->roles as $role)
                    <button type="button" class="fx-roleopt" aria-pressed="{{ $currentRole == $role->key ? 'true' : 'false' }}"
                                    wire:click="$set('currentRole', '{{ $role->key }}')">
                        <span class="fx-roleopt__name">{{ $role->name }}@if ($currentRole == $role->key)<x-fx.icon name="check" />@endif</span>
                        <span class="fx-roleopt__desc">{{ $role->description }}</span>
                    </button>
                @endforeach
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="stopManagingRole" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button wire:click="updateRole" wire:loading.attr="disabled">
                {{ __('Save') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

    <!-- Leave Team Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingLeavingTeam">
        <x-slot name="title">
            {{ __('Leave Team') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to leave this team?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingLeavingTeam')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button wire:click="leaveTeam" wire:loading.attr="disabled">
                {{ __('Leave') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>

    <!-- Remove Team Member Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingTeamMemberRemoval">
        <x-slot name="title">
            {{ __('Remove Team Member') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to remove this person from the team?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingTeamMemberRemoval')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button wire:click="removeTeamMember" wire:loading.attr="disabled">
                {{ __('Remove') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>
