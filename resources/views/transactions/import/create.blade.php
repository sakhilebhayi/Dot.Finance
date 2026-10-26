<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Import Transactions
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
            Upload a CSV with date, description, and amount columns. You choose how dates and amounts are read, and review the result before anything is saved. Importing the same statement twice is safe: rows already in your ledger are skipped.
        </p>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    @if ($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.82rem;">
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('transactions.import.upload') }}" enctype="multipart/form-data" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label for="transactions-import-create-account-id" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Account</label>
            <select id="transactions-import-create-account-id" name="account_id" required class="dot-input">
                <option value="">Select account</option>
                @foreach($accounts as $account)
                <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                @endforeach
            </select>
            <p style="font-size:0.7rem;color:var(--text-muted);margin:0.35rem 0 0;">Every row in the CSV will be attached to this account, in its currency.</p>
        </div>

        <div>
            <label for="transactions-import-create-file" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">CSV file</label>
            <input id="transactions-import-create-file" type="file" name="file" accept=".csv,.txt" required class="dot-input" />
            <p style="font-size:0.7rem;color:var(--text-muted);margin:0.35rem 0 0;">
                Recognised columns (case-insensitive): <strong>date</strong>, <strong>description</strong> (or memo/payee), <strong>amount</strong>, and optionally <strong>category</strong>.
                A negative amount is imported as an expense, positive as income. Comma, semicolon and tab separated files all work. Up to 5 MB.
            </p>
        </div>

        <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
            <a href="{{ route('transactions.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
            <button type="submit" class="dot-btn dot-btn-primary">Preview import</button>
        </div>
    </form>

</div>

</x-app-layout>
