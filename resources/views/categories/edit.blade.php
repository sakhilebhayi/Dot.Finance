<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Edit category
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Renaming or recolouring applies everywhere the category is used, including past transactions.</p>
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

    <form method="POST" action="{{ route('categories.update', $category) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PUT')

        <div>
            <label for="categories-edit-name" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Name</label>
            <input id="categories-edit-name" type="text" name="name" value="{{ old('name', $category->name) }}" required maxlength="255" class="dot-input" />
        </div>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="categories-edit-type" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Type</label>
                @if($typeLocked)
                <input type="text" value="{{ ucfirst($category->type) }}" disabled class="dot-input" />
                <input type="hidden" name="type" value="{{ $category->type }}">
                <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">Locked: transactions or budgets already use this category.</p>
                @else
                <select id="categories-edit-type" name="type" required class="dot-input">
                    <option value="expense" @selected(old('type', $category->type) === 'expense')>Expense</option>
                    <option value="income" @selected(old('type', $category->type) === 'income')>Income</option>
                </select>
                @endif
            </div>
            <div>
                <label for="categories-edit-color" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Colour</label>
                <input id="categories-edit-color" type="color" name="color" value="{{ old('color', $category->color) }}" style="height:36px;width:100%;padding:2px;border-radius:8px;border:1px solid var(--input-border);background:var(--input-bg);" />
            </div>
        </div>

        <div>
            <label for="categories-edit-icon" style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Icon (optional, a Material Symbols name)</label>
            <input id="categories-edit-icon" type="text" name="icon" value="{{ old('icon', $category->icon) }}" maxlength="10" class="dot-input" />
        </div>

        <div style="display:flex;gap:0.75rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save changes</button>
            <a href="{{ route('categories.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>

</div>

</x-app-layout>
