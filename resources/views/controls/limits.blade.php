<x-app-layout title="Spend limits">
<x-fx.page-head title="Spend limits" sub="The most an approver may approve. A person's own limits replace their role's. With no limit set there is no cap. Limits are per currency: someone with limits but none in a currency cannot approve in that currency." />

@include('controls._nav')

<div class="fx-grid fx-grid--form r-stack">
    <x-fx.panel flush title="Limits in force">
        @if ($limits->isEmpty())
        <x-fx.empty title="No spend limits.">No spend limits. Approvers can approve any amount their policy allows.</x-fx.empty>
        @else
        <x-fx.table caption="Limits in force">
            <thead><tr><th scope="col">Applies to</th><th scope="col" class="is-num">Limit</th><th scope="col">Period</th>@if ($canManage)<th scope="col"><span class="sr-only">Action</span></th>@endif</tr></thead>
            <tbody>
            @foreach ($limits as $limit)
                <tr>
                    <th scope="row" data-label="Applies to" class="is-lead"><span class="fx-cell-main">{{ $limit->member?->name ?? ($limit->roleEnum()?->label().' (role)') }}</span></th>
                    <td data-label="Limit" class="is-num"><x-fx.money :cents="$limit->limit_cents" :currency="$limit->currency" /></td>
                    <td data-label="Period">{{ $periods[$limit->period] ?? $limit->period }}</td>
                    @if ($canManage)
                    <td class="is-actions">
                        <form method="POST" action="{{ route('controls.limits.destroy', $limit) }}" data-fx-confirm="Remove this limit? The approver goes back to their role's limit, or to no cap if there is none." data-fx-confirm-label="Remove limit">@csrf @method('DELETE') <button type="submit" class="fx-linkbtn fx-linkbtn--danger">Remove<span class="sr-only"> limit for {{ $limit->member?->name ?? ($limit->roleEnum()?->label().' (role)') }}</span></button></form>
                    </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @endif
    </x-fx.panel>

    @if ($canManage)
    <x-fx.panel title="Set a limit">
        <form method="POST" action="{{ route('controls.limits.store') }}" class="fx-form">
            @csrf
            <x-fx.select name="who" id="limit-who" label="For" required>
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
            </x-fx.select>
            <div class="fx-row fx-row--2">
                <x-fx.input name="amount" id="limit-amount" label="Amount" inputmode="decimal" required figure />
                <x-fx.input name="currency" id="limit-currency" label="Currency" maxlength="3" required :value="$currency" figure />
            </div>
            <x-fx.select name="period" id="limit-period" label="Per">
                @foreach ($periods as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-fx.select>
            <div class="fx-formactions"><x-fx.button variant="primary" icon="check">Save limit</x-fx.button></div>
        </form>
    </x-fx.panel>
    @endif
</div>
</x-app-layout>
