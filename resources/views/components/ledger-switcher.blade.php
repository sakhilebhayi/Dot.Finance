@auth
@php
    $ledgerChoices = app(\App\Support\Ledger\LedgerContextManager::class)->contextsFor(Auth::user());
    $ledgerActive = \App\Support\Ledger\LedgerContext::current();
@endphp
<div class="ledger-switch">
    @if (count($ledgerChoices) > 1)
        {{-- The person belongs to at least one company: choose which ledger to work in. Each choice is a real POST, validated against what they belong to. --}}
        <form method="POST" action="{{ route('ledger.context.switch') }}" aria-label="Choose the ledger to work in">
            @csrf
            <div class="nav-section-label">Ledger</div>
            @foreach ($ledgerChoices as $choice)
                @php $isActive = $ledgerActive?->equals($choice) ?? false; @endphp
                <button type="submit" name="context" value="{{ $choice->isCompany() ? $choice->teamId : 'personal' }}" class="ledger-opt" @if ($isActive) aria-current="true" disabled @endif>
                    <span class="material-symbols-rounded" aria-hidden="true">{{ $choice->isCompany() ? 'apartment' : 'person' }}</span>
                    <span class="ledger-opt-name">{{ $choice->label() }}</span>
                </button>
            @endforeach
        </form>
    @else
        <div class="nav-section-label">Ledger</div>
        <div class="ledger-current"><span class="material-symbols-rounded" aria-hidden="true">person</span>Personal ledger</div>
    @endif
</div>
@endauth
