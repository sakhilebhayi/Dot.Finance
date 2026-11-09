@auth
@php
    $ledgerChoices = app(\App\Support\Ledger\LedgerContextManager::class)->contextsFor(Auth::user());
    $ledgerActive = \App\Support\Ledger\LedgerContext::current();
    $ledgerRole = \App\Support\Ledger\LedgerAccess::current(Auth::user());
@endphp
<div class="fx-ledger-switch ledger-switch">
    @if (count($ledgerChoices) > 1)
        {{-- The person belongs to at least one company: choose which ledger to work in. Each choice is a real POST, validated against what they belong to. --}}
        <form method="POST" action="{{ route('ledger.context.switch') }}" aria-label="Choose the ledger to work in">
            @csrf
            <p class="fx-ledger-switch__label">Ledger</p>
            @foreach ($ledgerChoices as $choice)
                @php $isActive = $ledgerActive?->equals($choice) ?? false; @endphp
                <button type="submit" name="context" value="{{ $choice->isCompany() ? $choice->teamId : 'personal' }}" class="fx-ledger-opt" @if ($isActive) aria-current="true" disabled @endif>
                    <x-fx.icon :name="$choice->isCompany() ? 'building-2' : 'user'" />
                    <span class="fx-ledger-opt__name">{{ $choice->label() }}</span>
                </button>
            @endforeach
            @if ($ledgerActive?->isCompany() && $ledgerRole)
                <p class="fx-ledger-role">Your role: {{ $ledgerRole->roleLabel() }}@unless ($ledgerRole->canRecordEntries()) (read-only)@endunless</p>
            @endif
        </form>
    @else
        <p class="fx-ledger-switch__label">Ledger</p>
        <div class="fx-ledger-opt" aria-current="true"><x-fx.icon name="user" /><span class="fx-ledger-opt__name">Personal ledger</span></div>
    @endif
</div>
@endauth
