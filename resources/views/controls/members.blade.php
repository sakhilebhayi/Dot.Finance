<x-app-layout>
<div class="page-pad controls-page">
    <h1>Members and roles</h1>
    <p>
        @if ($activeLedger?->isCompany())
            InfoDot decides how far each person can go. A role set here can narrow it or refine it inside that limit, but never go beyond it, and you cannot give a role above your own.
        @else
            A personal ledger has one member, you, and you are its owner.
        @endif
    </p>
    @include('controls._nav')

    <table class="controls-table">
        <caption>People with access to {{ $activeLedger?->label() }}</caption>
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
                <th scope="row">{{ $user->name }}<br><small>{{ $user->email }}</small></th>
                <td>{{ ucfirst($row['infodot']) }} ({{ $target->baselineRole()->label() }})</td>
                <td>
                    @if ($target->hasLocalRole())
                        {{ $target->financeRole()->label() }}
                        @if ($row['grant']?->grantor) <small>set by {{ $row['grant']->grantor->name }}</small> @endif
                    @else
                        None
                    @endif
                </td>
                <td>{{ $target->financeRole()->label() }}</td>
                <td>
                    @if ($canChange)
                        <form method="POST" action="{{ route('controls.members.update', $user->id) }}">
                            @csrf
                            @method('PUT')
                            <label for="role-{{ $user->id }}">Role for {{ $user->name }}</label>
                            <select id="role-{{ $user->id }}" name="role" class="dot-input">
                                <option value="inherit">Use the InfoDot role ({{ $target->baselineRole()->label() }})</option>
                                @foreach ($roles as $role)
                                    @if ($role->canBeGrantedOn($target->baselineRole()) && $role->rank() <= $access->financeRole()->rank())
                                        <option value="{{ $role->value }}" @selected($target->hasLocalRole() && $target->financeRole() === $role)>{{ $role->label() }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <button type="submit" class="dot-btn">Save role</button>
                        </form>
                    @else
                        <span>Not changeable by you</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>What each role can do</h2>
    <table class="controls-table">
        <caption>Capabilities by role</caption>
        <thead>
            <tr>
                <th scope="col">Capability</th>
                @foreach ($roles as $role)
                    <th scope="col">{{ $role->label() }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
        @foreach (\App\Enums\Capability::cases() as $capability)
            <tr>
                <th scope="row">{{ $capability->label() }}</th>
                @foreach ($roles as $role)
                    <td>{{ $role->grants($capability) ? 'Yes' : 'No' }}</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
    <dl class="controls-roles">
        @foreach ($roles as $role)
            <dt>{{ $role->label() }}</dt>
            <dd>{{ $role->description() }}</dd>
        @endforeach
    </dl>
</div>
</x-app-layout>
