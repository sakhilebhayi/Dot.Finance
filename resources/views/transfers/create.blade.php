<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Record a transfer
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
            Moves money between two of your own accounts. It is written as two linked entries, one out and one in, and never counts as income or spending.
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

    @if($accounts->count() < 2)
    <div class="dot-card" style="padding:2rem;text-align:center;color:var(--text-muted);font-size:0.82rem;">
        A transfer needs two accounts. <a href="{{ route('accounts.index') }}" style="color:#059669;font-weight:600;">Add another account</a> first.
    </div>
    @else
    <form method="POST" action="{{ route('transfers.store') }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="transfers-create-from-account-id" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">From account</label>
                <select id="transfers-create-from-account-id" name="from_account_id" required class="dot-input">
                    <option value="">Select account</option>
                    @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected((string) old('from_account_id') === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="transfers-create-to-account-id" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">To account</label>
                <select id="transfers-create-to-account-id" name="to_account_id" required class="dot-input">
                    <option value="">Select account</option>
                    @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected((string) old('to_account_id') === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="transfers-create-amount" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Amount leaving the source</label>
                <input id="transfers-create-amount" type="text" inputmode="decimal" name="amount" value="{{ old('amount') }}" required class="dot-input" placeholder="0.00" />
            </div>
            <div>
                <label for="transfers-create-converted-amount" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Amount arriving (different currencies only)</label>
                <input id="transfers-create-converted-amount" type="text" inputmode="decimal" name="converted_amount" value="{{ old('converted_amount') }}" class="dot-input" placeholder="Leave empty within one currency" />
            </div>
        </div>
        <p style="font-size:0.7rem;color:var(--text-muted);margin:-0.4rem 0 0;">
            Between accounts in different currencies you enter what actually arrived. Dot.Finance does not guess an exchange rate.
        </p>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="transfers-create-date" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Date</label>
                <input id="transfers-create-date" type="date" name="date" value="{{ old('date', now()->toDateString()) }}" required class="dot-input" />
            </div>
            <div>
                <label for="transfers-create-description" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Description (optional)</label>
                <input id="transfers-create-description" type="text" name="description" value="{{ old('description') }}" maxlength="255" class="dot-input" placeholder="Transfer" />
            </div>
        </div>

        <div>
            <label for="transfers-create-notes" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Notes (optional)</label>
            <input id="transfers-create-notes" type="text" name="notes" value="{{ old('notes') }}" maxlength="255" class="dot-input" />
        </div>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Record transfer</button>
            <a href="{{ route('transactions.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>
    @endif

</div>

</x-app-layout>
