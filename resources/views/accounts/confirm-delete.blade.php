<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Delete {{ $account->name }}?
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
            @if($transactionCount > 0)
            This account has <strong>{{ $transactionCount }} transaction{{ $transactionCount === 1 ? '' : 's' }}</strong>, and a ledger balance of <x-money :cents="$balanceCents" :currency="$account->currency" :tone="true" />. Deleting the account deletes those transactions too.
            @else
            This account has no transactions.
            @endif
        </p>
    </div>

    @if ($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.82rem;">
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('DELETE')

        <ul style="margin:0;padding-left:1.1rem;font-size:0.8rem;color:var(--text-secondary);line-height:1.7;">
            <li>Nothing is erased: the account and its transactions are marked deleted, stop counting everywhere, and every one is recorded in the <a href="{{ route('audit.index') }}" style="color:#059669;">audit log</a>.</li>
            <li>A transfer between this account and another leaves the other account's side alone. That money already moved.</li>
            <li>Budgets and recurring items are not affected; recurring items on this account stop generating entries.</li>
        </ul>

        @if($transactionCount > 0)
        <label style="display:flex;gap:0.6rem;align-items:flex-start;font-size:0.82rem;color:var(--text-primary);">
            <input type="checkbox" name="confirm" value="1" required style="margin-top:0.2rem;">
            <span>I understand that {{ $transactionCount }} transaction{{ $transactionCount === 1 ? '' : 's' }} will be deleted with this account.</span>
        </label>
        @endif

        <div style="display:flex;gap:0.75rem;">
            <button type="submit" class="dot-btn" style="background:#f87171;color:#09090b;">Delete account</button>
            <a href="{{ route('accounts.index') }}" class="dot-btn dot-btn-ghost">Keep it</a>
        </div>
    </form>

</div>

</x-app-layout>
