<x-app-layout title="Members and roles">
<x-fx.page-head title="Members and roles">
    <x-slot name="sub">
        @if ($activeLedger?->isCompany())
            InfoDot decides how far each person can go. A role set here can narrow it or refine it inside that limit, but never go beyond it, and you cannot give a role above your own.
        @else
            A personal ledger has one member, you, and you are its owner.
        @endif
    </x-slot>
</x-fx.page-head>

@include('controls._nav')

<x-fx.panel flush title="People with access to {{ $activeLedger?->label() }}" meta="The role in force is the one that counts">
    <x-fx.table caption="People with access to {{ $activeLedger?->label() }}">
        <thead>
            <tr>
                <th scope="col">Person</th>
                <th scope="col">From InfoDot</th>
                <th scope="col">Local role</th>
                <th scope="col">Role in force</th>
                <th scope="col">Change</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($members as $row)
            @php
                $user = $row['user'];
                $target = $row['access'];
                $canChange = $activeLedger?->isCompany() && $access->can(\App\Enums\Capability::ManageMembers)
                    && $target->financeRole() !== \App\Enums\FinanceRole::Owner
                    && $target->financeRole()->rank() <= $access->financeRole()->rank();
            @endphp
            <tr>
                <th scope="row" data-label="Person" class="is-lead">
                    <span class="fx-person"><x-fx.avatar :name="$user->name" /><span><span class="fx-cell-main">{{ $user->name }}</span><span class="fx-cell-sub">{{ $user->email }}</span></span></span>
                </th>
                <td data-label="From InfoDot"><span class="fx-cellbody">{{ ucfirst($row['infodot']) }} <span class="fx-cell-sub">({{ $target->baselineRole()->label() }})</span></span></td>
                <td data-label="Local role"><span class="fx-cellbody">
                    @if ($target->hasLocalRole())
                        {{ $target->financeRole()->label() }}
                        @if ($row['grant']?->grantor) <span class="fx-cell-sub">set by {{ $row['grant']->grantor->name }}</span> @endif
                    @else
                        <span class="fx-quiet">None</span>
                    @endif
                </span></td>
                <td data-label="Role in force"><x-fx.pill kind="plain">{{ $target->financeRole()->label() }}</x-fx.pill></td>
                <td data-label="Change">
                    @if ($canChange)
                        <form method="POST" action="{{ route('controls.members.update', $user->id) }}" class="fx-inline">
                            @csrf
                            @method('PUT')
                            <label for="role-{{ $user->id }}" class="sr-only">Role for {{ $user->name }}</label>
                            <select id="role-{{ $user->id }}" name="role" class="fx-select">
                                <option value="inherit">Use the InfoDot role ({{ $target->baselineRole()->label() }})</option>
                                @foreach ($roles as $role)
                                    @if ($role->canBeGrantedOn($target->baselineRole()) && $role->rank() <= $access->financeRole()->rank())
                                        <option value="{{ $role->value }}" @selected($target->hasLocalRole() && $target->financeRole() === $role)>{{ $role->label() }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <x-fx.button size="sm">Save role<span class="sr-only"> for {{ $user->name }}</span></x-fx.button>
                        </form>
                    @else
                        <span class="fx-quiet">Not changeable by you</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>

<x-fx.panel title="What each role is for">
    <dl class="fx-explain fx-explain--wide">
        @foreach ($roles as $role)
        <div><dt>{{ $role->label() }}</dt><dd>{{ $role->description() }}</dd></div>
        @endforeach
    </dl>
</x-fx.panel>

<x-fx.panel flush title="What each role can do" meta="A tick means the role can do it">
    <x-fx.table caption="Capabilities by role" scroll class="fx-table--matrix">
        <thead>
            <tr>
                <th scope="col">Capability</th>
                @foreach ($roles as $role)
                <th scope="col" class="is-center">{{ $role->label() }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
        @foreach (\App\Enums\Capability::cases() as $capability)
            <tr>
                <th scope="row">{{ $capability->label() }}</th>
                @foreach ($roles as $role)
                <td class="is-center">
                    @if ($role->grants($capability))<x-fx.icon name="check" class="fx-yes" /><span class="sr-only">Yes</span>@else<span class="fx-no" aria-hidden="true">&ndash;</span><span class="sr-only">No</span>@endif
                </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>
</x-app-layout>
