<x-app-layout :title="'Edit '.$budget->name">
<x-fx.page-head :title="'Edit '.$budget->name" sub="One row per account (and department or project, if you use them). Enter each month, or give a yearly total to spread evenly. Revenue accounts are targets, expense accounts are limits." />
<x-fx.errors />
@php
    $rows = old('rows') ? array_values(old('rows')) : $rows;
    $blank = max(3, 6 - count($rows));
    for ($i = 0; $i < $blank; $i++) { $rows[] = []; }
@endphp
<form method="POST" action="{{ route('planning.budgets.update', $budget) }}">
    @csrf @method('PUT')
    <div class="fx-tablewrap table-scroll">
    <table class="fx-table fx-table--keep rp-matrix">
        <caption class="sr-only">Plan amounts by account and month</caption>
        <thead><tr><th scope="col">Account</th><th scope="col">Department or project</th><th scope="col">Yearly total</th>@foreach ($months as $m)<th scope="col" class="is-num">{{ \Carbon\CarbonImmutable::parse($m)->format('M y') }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($rows as $i => $row)
        <tr>
            <td>
                <select class="fx-select" name="rows[{{ $i }}][gl_account_id]" aria-label="Account, row {{ $i + 1 }}">
                    <option value="">Not used</option>
                    @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((string) ($row['account_id'] ?? $row['gl_account_id'] ?? '') === (string) $a->id)>{{ $a->code }} {{ $a->name }} ({{ $a->class === 'income' ? 'target' : 'limit' }})</option>@endforeach
                </select>
            </td>
            <td>
                <select class="fx-select" name="rows[{{ $i }}][dimension]" aria-label="Department or project, row {{ $i + 1 }}">
                    <option value="">None</option>
                    @foreach ($dimensions as $d)<option value="{{ $d->ref }}" @selected(($row['dimension'] ?? '') === $d->ref)>{{ $d->name }}</option>@endforeach
                </select>
            </td>
            <td><input class="fx-input fx-input--figure" name="rows[{{ $i }}][annual]" value="{{ $row['annual'] ?? '' }}" inputmode="decimal" aria-label="Yearly total, row {{ $i + 1 }}"></td>
            @foreach ($months as $m)
            <td><input class="fx-input fx-input--figure" name="rows[{{ $i }}][months][{{ $m }}]" inputmode="decimal" aria-label="{{ \Carbon\CarbonImmutable::parse($m)->format('F Y') }}, row {{ $i + 1 }}"
                value="{{ isset($row['months'][$m]) ? (is_int($row['months'][$m]) ? \App\Support\Money::toDecimalString($row['months'][$m]) : $row['months'][$m]) : '' }}"></td>
            @endforeach
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    <p class="fx-hint">A yearly total spreads evenly over the months (the remainder goes to the earliest months) and replaces that row's monthly entries. {{ $dimensions->isEmpty() ? 'Name departments and projects under Departments and projects to use them here.' : '' }}</p>
    <div class="fx-formactions"><x-fx.button variant="primary">Save amounts</x-fx.button><x-fx.button :href="route('planning.budgets.show', $budget)" variant="ghost">Cancel</x-fx.button></div>
</form>
</x-app-layout>
