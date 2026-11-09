<x-app-layout title="Can I afford it?">
<x-fx.page-head title="Can I afford it?" sub="Test a purchase against the cash projection and a safety buffer you choose. The answer is a projection, never a guarantee." />
<x-fx.errors />

<form method="GET" action="{{ route('planning.afford') }}" class="rp-toolbar" aria-label="The purchase">
    <x-fx.input name="amount" label="Amount" :value="$input['amount'] ?? ''" figure />
    <x-fx.input name="on" label="On" type="date" :value="$input['on'] ?? $today->addDays(7)->toDateString()" />
    <x-fx.select name="mode" label="How often">
        <option value="once" @selected(($input['mode'] ?? 'once') === 'once')>Once</option>
        <option value="monthly" @selected(($input['mode'] ?? '') === 'monthly')>Every month</option>
    </x-fx.select>
    <x-fx.input name="months" label="For how many months" type="number" :value="$input['months'] ?? 1" hint="Only for monthly." />
    <x-fx.input name="buffer" label="Keep at least" :value="$input['buffer'] ?? ''" hint="Your safety buffer." figure />
    <x-fx.select name="scenario" label="Under a scenario" :optional="true">
        <option value="">None</option>
        @foreach ($scenarios as $s)<option value="{{ $s->id }}" @selected((string) ($input['scenario'] ?? '') === (string) $s->id)>{{ $s->name }}</option>@endforeach
    </x-fx.select>
    <div class="rp-toolbar__actions"><x-fx.button variant="primary">Check</x-fx.button></div>
</form>

@if ($result)
@php $cur = $result['currency']; @endphp
<section class="rp-answer" aria-labelledby="afford-answer">
    <h2 class="fx-section-title" id="afford-answer">{{ $result['stays_above_buffer'] ? 'Projected to stay above your buffer' : 'Projected to fall below your buffer' }} <x-fx.pill>projection</x-fx.pill></h2>
    <p>{{ $result['headline'] }}</p>
    @if ($result['already_short'])<p><strong>Note:</strong> even without this spend, cash is projected to go below the buffer on {{ \Carbon\CarbonImmutable::parse($result['without']['shortage']['date'])->format('j M Y') }}.</p>@endif
    <p class="fx-hint">{{ $result['caveat'] }}</p>
</section>
@include('planning._chart', ['base' => $result['with'], 'alt' => $result['without'], 'buffer' => $result['buffer_cents'], 'currency' => $cur])
<p class="fx-hint">Solid dashed line: with the spend. Dotted line: without it.</p>
@endif
</x-app-layout>
