<x-app-layout>
<div class="page-pad controls-page">
    <h1>Spend limits</h1>
    <p>The most an approver may approve. A person's own limits replace their role's. With no limit set there is no cap. Limits are per currency: someone with limits but none in a currency cannot approve in that currency.</p>
    @include('controls._nav')

    <table class="controls-table">
        <caption>Limits in force</caption>
        <thead><tr><th scope="col">Applies to</th><th scope="col">Limit</th><th scope="col">Period</th>@if ($canManage)<th scope="col">Action</th>@endif</tr></thead>
        <tbody>
        @forelse ($limits as $limit)
            <tr>
                <th scope="row">{{ $limit->member?->name ?? ($limit->roleEnum()?->label().' (role)') }}</th>
                <td>{{ \App\Support\Money::format($limit->limit_cents, $limit->currency) }}</td>
                <td>{{ $periods[$limit->period] ?? $limit->period }}</td>
                @if ($canManage)
                    <td><form method="POST" action="{{ route('controls.limits.destroy', $limit) }}">@csrf @method('DELETE') <button type="submit" class="dot-btn">Remove</button></form></td>
                @endif
            </tr>
        @empty
            <tr><td colspan="4">No spend limits. Approvers can approve any amount their policy allows.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if ($canManage)
        <h2>Set a limit</h2>
        <form method="POST" action="{{ route('controls.limits.store') }}">
            @csrf
            <label for="limit-who">For</label>
            <select id="limit-who" name="who" class="dot-input" required>
                <optgroup label="A person">
                    @foreach ($members as $member)
                        <option value="user:{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Everyone with a role">
                    @foreach ($roles as $role)
                        <option value="role:{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </optgroup>
            </select>
            <label for="limit-amount">Amount</label>
            <input id="limit-amount" name="amount" inputmode="decimal" class="dot-input" required>
            <label for="limit-currency">Currency</label>
            <input id="limit-currency" name="currency" maxlength="3" class="dot-input" value="{{ $currency }}" required>
            <label for="limit-period">Per</label>
            <select id="limit-period" name="period" class="dot-input">
                @foreach ($periods as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="dot-btn dot-btn-primary">Save limit</button>
        </form>
    @endif
</div>
</x-app-layout>
