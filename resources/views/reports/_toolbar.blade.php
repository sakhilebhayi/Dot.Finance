@php
    $key = $definition->key();
    $point = $definition->isPointInTime();
    $in = $input;
    $selectedPreset = $request->period->preset;
@endphp
<form method="GET" action="{{ route($routeName) }}" class="rp-toolbar" aria-label="Report settings" data-fx-custom-dates>
    @unless ($point)
    <x-fx.select name="preset" label="Period">
        @foreach ($periodPresets as $value => $label)
        <option value="{{ $value }}" @selected($selectedPreset === $value)>{{ $label }}</option>
        @endforeach
    </x-fx.select>
    <x-fx.input name="from" label="From" type="date" :value="$request->period->from" hint="Changing a date switches Period to Custom dates." />
    <x-fx.input name="to" label="To" type="date" :value="$request->period->to" />
    @else
    <x-fx.input name="to" label="As at" type="date" :value="$request->period->to" />
    @endunless

    @if ($definition->supportsComparison())
    <x-fx.select name="compare" label="Compare with">
        @foreach ($comparisons as $value => $label)
        <option value="{{ $value }}" @selected(($in['compare'] ?? 'none') === $value)>{{ $label }}</option>
        @endforeach
    </x-fx.select>
    @endif

    @if ($definition->supportsBasis())
    <x-fx.select name="basis" label="Basis" hint="Accrual counts a sale when issued; cash counts it when paid.">
        <option value="accrual" @selected(($in['basis'] ?? 'accrual') === 'accrual')>Accrual</option>
        <option value="cash" @selected(($in['basis'] ?? '') === 'cash')>Cash</option>
    </x-fx.select>
    @endif

    @if ($key === 'profit-loss')
    <x-fx.select name="monthly" label="Columns">
        <option value="0" @selected(($in['monthly'] ?? '0') !== '1')>Period total</option>
        <option value="1" @selected(($in['monthly'] ?? '') === '1')>One column per month</option>
    </x-fx.select>
    @elseif ($key === 'cash-flow-statement')
    <x-fx.select name="view" label="Method">
        <option value="indirect" @selected(($in['view'] ?? 'indirect') === 'indirect')>Indirect (from profit)</option>
        <option value="direct" @selected(($in['view'] ?? '') === 'direct')>Direct (from the bank)</option>
    </x-fx.select>
    @elseif ($key === 'general-ledger')
    <x-fx.select name="account" label="Account" :optional="true" wrap="rp-toolbar__wide">
        <option value="">Every account with activity</option>
        @foreach ($accounts as $a)
        <option value="{{ $a->id }}" @selected((string) ($in['account'] ?? '') === (string) $a->id)>{{ $a->code }} {{ $a->name }}</option>
        @endforeach
    </x-fx.select>
    @elseif (in_array($key, ['revenue', 'expenses'], true))
    <x-fx.select name="view" label="Analyse">
        @foreach (\App\Services\Reporting\Reports\BreakdownReport::VIEWS as $value => $label)
        <option value="{{ $value }}" @selected(($in['view'] ?? 'account') === $value)>{{ $label }}</option>
        @endforeach
    </x-fx.select>
    @elseif ($key === 'budget-vs-actual')
    <x-fx.select name="budget" label="Plan" :optional="true" wrap="rp-toolbar__wide">
        <option value="">The plan covering the period</option>
        @foreach ($plans ?? [] as $plan)
        <option value="{{ $plan->id }}" @selected((string) ($in['budget'] ?? '') === (string) $plan->id)>{{ $plan->name }} (v{{ $plan->version }}, {{ $plan->status }})</option>
        @endforeach
    </x-fx.select>
    <x-fx.select name="view" label="Group by">
        <option value="account" @selected(($in['view'] ?? 'account') === 'account')>Account</option>
        <option value="dimension" @selected(($in['view'] ?? '') === 'dimension')>Department or project</option>
    </x-fx.select>
    @endif

    <div class="rp-toolbar__actions">
        <x-fx.button variant="primary">Show report</x-fx.button>
    </div>
</form>

<div class="fx-cluster rp-noprint">
    @if (count($presets))
    <nav aria-label="Saved views" class="fx-cluster">
        <span class="fx-hint">Saved views:</span>
        @foreach ($presets as $preset)
        <span class="fx-cluster">
            <a class="fx-link" href="{{ route($routeName, $preset->params) }}">{{ $preset->name }}</a>
            <form method="POST" action="{{ route('reports.presets.destroy', $preset) }}" data-fx-confirm="Remove the saved view &quot;{{ $preset->name }}&quot;?" data-fx-confirm-label="Remove">
                @csrf @method('DELETE')
                <button type="submit" class="fx-linkbtn fx-linkbtn--danger" aria-label="Remove saved view {{ $preset->name }}">Remove</button>
            </form>
        </span>
        @endforeach
    </nav>
    @endif
</div>

<details class="rp-more rp-noprint">
<summary>Save this view or export</summary>
<div class="fx-cluster rp-more__body">
    <form method="POST" action="{{ route('reports.presets.store') }}" class="fx-cluster" aria-label="Save this view">
        @csrf
        <input type="hidden" name="report" value="{{ $key }}">
        @foreach ($input as $name => $value)@if (is_scalar($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif @endforeach
        <x-fx.input name="name" label="Save this view as" id="preset-name" :bind="false" />
        <x-fx.button variant="ghost" size="sm">Save view</x-fx.button>
    </form>
    @if ($canExport)
    <form method="POST" action="{{ route('reports.exports.store') }}" class="fx-cluster" aria-label="Export this report">
        @csrf
        <input type="hidden" name="report" value="{{ $key }}">
        @foreach ($input as $name => $value)@if (is_scalar($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif @endforeach
        <x-fx.select name="format" label="Export as" id="export-format">
            <option value="csv">CSV</option>
            <option value="xlsx">Spreadsheet (.xlsx)</option>
        </x-fx.select>
        <x-fx.button variant="ghost" size="sm" icon="download">Export</x-fx.button>
    </form>
    @endif
</div>
</details>
