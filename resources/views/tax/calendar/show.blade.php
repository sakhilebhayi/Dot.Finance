<x-app-layout :title="$deadline->label()">
    @php $s = $deadline->displayStatus($today); @endphp
    <x-fx.page-head :title="$deadline->label()" :sub="$deadline->periodText()">
        <x-slot name="actions">
            @if ($deadline->obligation === 'vat201')<x-fx.button :href="route('tax.vat.index')">VAT returns</x-fx.button>@endif
            @if ($deadline->obligation === 'emp201')<x-fx.button :href="route('tax.emp201.index')">Employer taxes</x-fx.button>@endif
            @if ($deadline->taxReturn)<x-fx.button :href="route('tax.returns.show', $deadline->taxReturn)" variant="primary">Open the return</x-fx.button>@endif
        </x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    <div class="fx-grid fx-grid--lead">
        <x-fx.panel title="The date">
            <x-fx.statement>
                <x-fx.statement-row label="Due">{{ $deadline->due_date->format('l j F Y') }}</x-fx.statement-row>
                <x-fx.statement-row label="Before business-day handling">{{ $deadline->base_due_date->format('l j F Y') }}</x-fx.statement-row>
                @if ($deadline->payment_due_date)<x-fx.statement-row label="Payment outside eFiling">{{ $deadline->payment_due_date->format('l j F Y') }}</x-fx.statement-row>@endif
                <x-fx.statement-row label="Status"><x-fx.pill :kind="$s === 'overdue' ? 'variance' : (in_array($s, ['submitted', 'paid'], true) ? 'ticked' : 'plain')">{{ ucfirst($s) }}</x-fx.pill></x-fx.statement-row>
                <x-fx.statement-row label="How sure we are of this date">{{ ucfirst($deadline->confidence) }}</x-fx.statement-row>
            </x-fx.statement>
            @if ($deadline->adjustment_note)<p class="fx-hint">{{ $deadline->adjustment_note }}</p>@endif
            <x-tax.basis basis="calculated" />
            <x-slot name="foot">@include("tax._rules", ["versions" => $deadline->rule_versions ?? []])</x-slot>
        </x-fx.panel>

        <x-fx.panel title="What is missing">
            @if (count($missing) === 0)
                <p><x-fx.icon name="check" class="fx-tick-draw" /> Nothing missing that we can detect from your records.</p>
            @else
                <ul class="fx-list">@foreach ($missing as $m)<li><strong>{{ $m['label'] }}</strong><p class="fx-hint">{{ $m['detail'] }} @if($m['href'])<a class="fx-link" href="{{ $m['href'] }}">Fix it</a>@endif</p></li>@endforeach</ul>
            @endif
        </x-fx.panel>
    </div>

    <x-fx.panel title="Checklist" meta="Ticked by the system where it can be checked from your records; by you otherwise">
        <ul class="fx-list">
        @foreach ($checklist as $item)
            <li>
                <span class="fx-cluster">
                    @if ($item['done'])<x-fx.pill kind="ticked">Done</x-fx.pill>@else<x-fx.pill>Open</x-fx.pill>@endif
                    <strong>{{ $item['label'] }}</strong>
                    <x-fx.pill kind="plain">{{ $item['auto'] ? 'Checked by the system' : 'Ticked by you' }}</x-fx.pill>
                </span>
                @if ($item['detail'])<p class="fx-hint">{{ $item['detail'] }}</p>@endif
                @if ($item['href'] && ! $item['done'])<a class="fx-link" href="{{ $item['href'] }}">Go there</a>@endif
                @if (! $item['auto'] && $canManage)
                    <form method="POST" action="{{ route('tax.calendar.tick', $deadline) }}" class="fx-cluster">
                        @csrf
                        <input type="hidden" name="item" value="{{ $item['key'] }}">
                        @if ($item['done'])<input type="hidden" name="undo" value="1"><x-fx.button size="sm">Untick</x-fx.button>
                        @else<label class="sr-only" for="note-{{ $item['key'] }}">Note for {{ $item['label'] }}</label><input id="note-{{ $item['key'] }}" name="note" class="fx-input" maxlength="255" placeholder="Note (optional)"><x-fx.button size="sm">Tick</x-fx.button>@endif
                    </form>
                @endif
            </li>
        @endforeach
        </ul>
    </x-fx.panel>

    @if ($canManage && $deadline->status === 'open')
    <x-fx.panel title="Already dealt with elsewhere?" meta="Set it aside with a reason">
        <form method="POST" action="{{ route('tax.calendar.waive', $deadline) }}" class="fx-form fx-cluster">
            @csrf
            <x-fx.input name="reason" label="Why is this not needed here" hint="For example: filed before we started using Dot.Finance." />
            <x-fx.button data-fx-confirm="Set this deadline aside? Your reason is kept on record." data-fx-confirm-label="Set aside">Set aside</x-fx.button>
        </form>
    </x-fx.panel>
    @endif
    @include('tax._footer')
</x-app-layout>
