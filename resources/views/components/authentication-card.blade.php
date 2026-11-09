{{-- Sign-in and account recovery pages: the form on a ruled sheet, and beside it what the product promises, in words. --}}
<div class="fx-auth">
    <div class="fx-auth__panel">
        <div class="fx-auth__logo">{{ $logo }}</div>
        <div class="fx-auth__sheet">
            {{ $slot }}
        </div>
    </div>
    <aside class="fx-auth__aside" aria-label="About Dot.Finance">
        <p class="fx-auth__lede">Every figure traces back to the entries behind it.</p>
        <ul class="fx-auth__list" role="list">
            <li><x-fx.icon name="check" />Each balance is worked out from the ledger and checked a second way. They have to agree to the cent.</li>
            <li><x-fx.icon name="check" />Every change is written to an audit log that cannot be edited.</li>
            <li><x-fx.icon name="check" />No bank linking. You enter or import the numbers, and nothing leaves your ledger.</li>
        </ul>
    </aside>
</div>
