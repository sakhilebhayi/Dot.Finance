@php
    /** How a reading of a document is shown: what was read, how sure the reader was of each figure, and whether a person must look. */
    $fields = $v['fields'] ?? [];
    $conf = $v['confidence'] ?? [];
    $tag = fn (string $key) => match ($conf[$key] ?? 'low') { 'high' => ['Figures agree', 'ticked'], 'medium' => ['Read, not cross-checked', 'neutral'], default => ['Unsure', 'variance'] };
    $rows = [
        ['Supplier', $fields['supplier_name'] ?? null, 'supplier_name'],
        ['Date', ! empty($fields['bill_date']) ? \Carbon\CarbonImmutable::parse($fields['bill_date'])->format('j M Y') : null, 'bill_date'],
        ['Total', isset($fields['total_cents']) ? \App\Support\Money::format((int) $fields['total_cents'], $fields['currency'] ?? null) : null, 'total_cents'],
    ];
    $missing = collect($rows)->filter(fn ($r) => $r[1] === null)->pluck(0)->all();
    $unsure = collect($rows)->filter(fn ($r) => $r[1] !== null && ($conf[$r[2]] ?? 'low') !== 'high')->pluck(0)->all();
    $warnings = $v['warnings'] ?? [];
    $check = $missing !== [] || $unsure !== [] || $warnings !== [];
@endphp
<div class="pu-read">
    <dl class="pu-read__fields">
        @foreach ($rows as [$label, $value, $key])
        <div class="pu-read__row"><dt>{{ $label }}</dt>
            <dd>@if ($value !== null)<span class="{{ $key === 'total_cents' ? 'fx-figure' : '' }}">{{ $value }}</span> <x-fx.pill :kind="$tag($key)[1]">{{ $tag($key)[0] }}</x-fx.pill>@else<span class="pu-read__none">Not found on the document</span>@endif</dd></div>
        @endforeach
    </dl>
    @if ($check)
    <p class="pu-read__check"><x-fx.pill kind="variance">Check this</x-fx.pill>
        {{ $warnings !== [] ? $warnings[0] : ($missing !== [] ? 'Not found: '.implode(', ', array_map('strtolower', $missing)).'.' : 'Compare '.implode(' and ', array_map('strtolower', $unsure)).' with the document before you use it.') }}</p>
    @else
    <p class="pu-read__check pu-read__check--ok"><x-fx.pill kind="ticked">Looks complete</x-fx.pill> Subtotal, VAT and total agree. Still open the document and compare.</p>
    @endif
</div>
