{{-- One line under the title says what this report covers. The working (who, when, which rule versions) sits behind a
     disclosure, open in the print view so a printed copy still carries it. --}}
@php
    $h = $result->header;
    $summary = collect([$h['ledger'] ?? null, $h['period'] ?? null, ! empty($h['comparison']) ? 'compared with '.$h['comparison'] : null, $h['currency'] ?? null, $h['basis'] ?? null])->filter()->implode(' · ');
    $labels = ['Posting rules' => 'Posting rules version', 'Chart of accounts' => 'Chart of accounts used', 'Journal sequence' => 'Journal entries counted up to'];
@endphp
<div class="rp-header" aria-label="About this report">
    <p class="rp-header__line">{{ $summary }}</p>
    <details class="rp-header__working" @if (! empty($open)) open @endif>
        <summary>Show working</summary>
        <dl class="rp-header__dl">
            <div><dt>Ledger</dt><dd>{{ $h['ledger'] }}</dd></div>
            <div><dt>Period</dt><dd>{{ $h['period'] }}</dd></div>
            @if (! empty($h['comparison']))<div><dt>Compared with</dt><dd>{{ $h['comparison'] }}</dd></div>@endif
            <div><dt>Currency</dt><dd>{{ $h['currency'] }}</dd></div>
            <div><dt>Basis</dt><dd>{{ $h['basis'] }}</dd></div>
            <div><dt>Generated</dt><dd>{{ \Carbon\CarbonImmutable::parse($h['generated_at'])->format('j M Y H:i') }}</dd></div>
            @foreach ($h['rule_versions'] as $name => $version)
            <div><dt>{{ $labels[$name] ?? $name }}</dt><dd>{{ $version }}</dd></div>
            @endforeach
        </dl>
    </details>
</div>
