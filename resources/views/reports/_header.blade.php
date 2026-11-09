@php $h = $result->header; @endphp
<dl class="rp-header" aria-label="About this report">
    <div><dt>Ledger</dt><dd>{{ $h['ledger'] }}</dd></div>
    <div><dt>Period</dt><dd>{{ $h['period'] }}</dd></div>
    @if (! empty($h['comparison']))<div><dt>Compared with</dt><dd>{{ $h['comparison'] }}</dd></div>@endif
    <div><dt>Currency</dt><dd>{{ $h['currency'] }}</dd></div>
    <div><dt>Basis</dt><dd>{{ $h['basis'] }}</dd></div>
    <div><dt>Generated</dt><dd>{{ \Carbon\CarbonImmutable::parse($h['generated_at'])->format('j M Y H:i') }}</dd></div>
    <div><dt>Rules</dt><dd>{{ collect($h['rule_versions'])->map(fn ($v, $k) => $k.' '.$v)->implode(', ') }}</dd></div>
</dl>
