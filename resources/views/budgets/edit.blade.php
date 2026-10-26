<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Edit budget
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">A budget counts expenses in its category and currency for its month only.</p>
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

    <form method="POST" action="{{ route('budgets.update', $budget) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PUT')

        <div>
            <label for="budgets-edit-category-id" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Category</label>
            <select id="budgets-edit-category-id" name="category_id" required class="dot-input">
                @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('category_id', $budget->category_id) === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="budgets-edit-amount" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Monthly limit</label>
                <input id="budgets-edit-amount" type="text" inputmode="decimal" name="amount" value="{{ old('amount', $budget->amount) }}" required class="dot-input" />
            </div>
            <div>
                <label for="budgets-edit-currency" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Currency</label>
                <select id="budgets-edit-currency" name="currency" required class="dot-input">
                    @foreach($currencies as $code)
                    <option value="{{ $code }}" @selected(old('currency', $budget->currency) === $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="budgets-edit-month" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Month</label>
                <select id="budgets-edit-month" name="month" required class="dot-input">
                    @foreach(range(1, 12) as $m)
                    <option value="{{ $m }}" @selected((int) old('month', $budget->month) === $m)>{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="budgets-edit-year" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Year</label>
                <input id="budgets-edit-year" type="number" name="year" value="{{ old('year', $budget->year) }}" required class="dot-input" />
            </div>
        </div>

        <div style="display:flex;gap:0.75rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save changes</button>
            <a href="{{ route('budgets.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>

</div>

</x-app-layout>
