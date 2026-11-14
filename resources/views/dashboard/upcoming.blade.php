<section class="fxd-sec fxd-arrive" id="coming-up" aria-labelledby="fxd-up-h">
    <div class="fxd-sec__h"><h2 id="fxd-up-h">Coming up</h2>@if ($upcoming->count() > 0)<a href="{{ route('recurring.index') }}">All repeating items</a>@endif</div>
    @if ($upcoming->count() > 0)
    <ul class="fxd-list fxd-rows" role="list">
        @foreach ($upcoming as $rule)
        @php $overdue = $rule->next_occurrence_date->lt(now()->startOfDay()); @endphp
        <li class="fxd-up fxd-row-link">
            <span>
                <a href="{{ route('recurring.index') }}" class="fxd-name">{{ $rule->description }}</a>
                <span class="fxd-meta">{{ $rule->type === 'income' ? 'money in' : 'money out' }}, due <span class="fx-date">{{ $rule->next_occurrence_date->format('Y-m-d') }}</span></span>
                @if ($overdue)<x-fx.pill kind="variance">Past its date</x-fx.pill>@endif
            </span>
            <x-fx.money :cents="$rule->type === 'income' ? $rule->amount_cents : -$rule->amount_cents" :currency="$rule->currency" :signed="true" />
        </li>
        @endforeach
    </ul>
    @else
    <x-fx.empty title="Nothing is due in the next 14 days.">
        Repeating items (rent, salary, a subscription) appear here a fortnight before they are due. Tick &ldquo;Repeat&rdquo; when you add a transaction.
        <x-slot name="action">
            @if ($canRecord)<x-fx.button :href="route('transactions.create')" icon="plus">Add a repeating item</x-fx.button>@endif
        </x-slot>
    </x-fx.empty>
    @endif
</section>
