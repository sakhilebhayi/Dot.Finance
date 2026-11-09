<x-app-layout title="Departments and projects">
<x-fx.page-head title="Departments and projects" sub="Give the references journal lines carry (for example department:3 or project:12) a name, so reports and plans can show them." />
<x-fx.flash /><x-fx.errors />

@if ($canManage)
<form method="POST" action="{{ route('planning.dimensions.store') }}" class="rp-toolbar" aria-label="Name a department or project">
    @csrf
    <x-fx.select name="kind" label="Kind">
        @foreach ($kinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
    </x-fx.select>
    <x-fx.input name="code" label="Code" hint="Letters, digits, dash. The reference becomes kind:code." />
    <x-fx.input name="name" label="Name" />
    <div class="rp-toolbar__actions"><x-fx.button variant="primary">Add</x-fx.button></div>
</form>
@endif

@if ($dimensions->isEmpty())
<x-fx.empty title="Nothing named yet.">Journal lines tagged with a department or project still report under their raw reference. Naming them makes the reports readable.</x-fx.empty>
@else
<x-fx.panel flush>
<x-fx.table caption="Named departments and projects">
    <thead><tr><th scope="col">Reference</th><th scope="col">Kind</th><th scope="col">Name</th><th scope="col">Active</th>@if ($canManage)<th scope="col" class="is-actions"><span class="sr-only">Save</span></th>@endif</tr></thead>
    <tbody>
    @foreach ($dimensions as $d)
    <tr>
        <th scope="row" data-label="Reference" class="is-lead">{{ $d->ref }}</th>
        <td data-label="Kind">{{ $kinds[$d->kind] ?? $d->kind }}</td>
        @if ($canManage)
        <td data-label="Name" colspan="3">
            <form method="POST" action="{{ route('planning.dimensions.update', $d) }}" class="fx-cluster">
                @csrf @method('PUT')
                <input class="fx-input" name="name" value="{{ $d->name }}" aria-label="Name of {{ $d->ref }}" required>
                <select class="fx-select" name="is_active" aria-label="Active"><option value="1" @selected($d->is_active)>Active</option><option value="0" @selected(! $d->is_active)>Retired</option></select>
                <x-fx.button variant="ghost" size="sm">Save</x-fx.button>
            </form>
        </td>
        @else
        <td data-label="Name">{{ $d->name }}</td><td data-label="Active">{{ $d->is_active ? 'Yes' : 'No' }}</td>
        @endif
    </tr>
    @endforeach
    </tbody>
</x-fx.table>
</x-fx.panel>
@endif
</x-app-layout>
