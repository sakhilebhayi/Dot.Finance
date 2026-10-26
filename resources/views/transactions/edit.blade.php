<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Edit Transaction
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Update this transaction's details.</p>
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

    <form method="POST" action="{{ route('transactions.update', $transaction) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PUT')

        <div>
            <label for="transactions-edit-description" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Description</label>
            <input id="transactions-edit-description" type="text" name="description" value="{{ old('description', $transaction->description) }}" required maxlength="255" class="dot-input" />
        </div>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="transactions-edit-amount" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Amount</label>
                <input id="transactions-edit-amount" type="text" inputmode="decimal" name="amount" value="{{ old('amount', $transaction->amount) }}" required class="dot-input" />
                <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">In {{ $transaction->currency }}, the currency of its account.</p>
            </div>
            <div>
                <label for="transactions-edit-type" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Type</label>
                <select id="transactions-edit-type" name="type" required class="dot-input" id="type-select">
                    @foreach(['expense', 'income'] as $type)
                    <option value="{{ $type }}" @selected(old('type', $transaction->type) === $type)>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="transactions-edit-account-id" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Account</label>
                <select id="transactions-edit-account-id" name="account_id" required class="dot-input">
                    @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected((string) old('account_id', $transaction->account_id) === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="transactions-edit-category-id" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Category (optional)</label>
                <select id="transactions-edit-category-id" name="category_id" class="dot-input" id="category-select">
                    <option value="">Uncategorized</option>
                    @foreach($categories as $category)
                    <option value="{{ $category->id }}" data-type="{{ $category->type }}" @selected((string) old('category_id', $transaction->category_id) === (string) $category->id)>{{ $category->name }} ({{ $category->type }}){{ $category->trashed() ? ' — deleted' : '' }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label for="transactions-edit-date" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Date</label>
            <input id="transactions-edit-date" type="date" name="date" value="{{ old('date', $transaction->date->toDateString()) }}" required class="dot-input" />
        </div>

        <div>
            <label for="transactions-edit-notes" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Notes (optional)</label>
            <input id="transactions-edit-notes" type="text" name="notes" value="{{ old('notes', $transaction->notes) }}" maxlength="255" class="dot-input" />
        </div>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save Changes</button>
            <a href="{{ route('transactions.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>

</div>

<script>
    // A category can only be used on a transaction of its own type; hide the others as the type changes.
    (function () {
        var type = document.getElementById('type-select');
        var category = document.getElementById('category-select');
        if (!type || !category) return;
        function sync() {
            Array.prototype.forEach.call(category.options, function (o) {
                if (!o.dataset.type) return;
                var match = o.dataset.type === type.value;
                o.hidden = !match;
                o.disabled = !match;
                if (!match && o.selected) category.value = '';
            });
        }
        type.addEventListener('change', sync);
        sync();
    })();
</script>

</x-app-layout>
