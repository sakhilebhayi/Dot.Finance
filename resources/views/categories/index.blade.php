<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Categories
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Organize transactions and budgets by category.</p>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    @php $canAdd = auth()->user()->can('create', \App\Models\Category::class); @endphp

    <div class="r-stack" style="display:grid;grid-template-columns:{{ $canAdd ? '1fr 340px' : '1fr' }};gap:1.5rem;align-items:start;">

        <div class="dot-card" style="overflow:hidden;">
            @if($categories->count() > 0)
            <div class="table-scroll" style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--divider);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Name</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Type</th>
                            <th style="padding:0.75rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Transactions</th>
                            @if($canAdd)<th style="padding:0.75rem 1.5rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Actions</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($categories as $category)
                        <tr style="border-bottom:1px solid var(--divider);">
                            <td style="padding:0.875rem 1.5rem;font-size:0.82rem;color:var(--text-primary);">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $category->color }};margin-right:0.5rem;"></span>
                                {{ $category->name }}
                            </td>
                            <td style="padding:0.875rem 1rem;font-size:0.78rem;color:var(--text-secondary);text-transform:capitalize;">{{ $category->type }}</td>
                            <td style="padding:0.875rem 1rem;text-align:right;font-size:0.78rem;color:var(--text-muted);">{{ $category->transactions_count }}</td>
                            @if($canAdd)
                            <td style="padding:0.875rem 1.5rem;text-align:right;white-space:nowrap;">
                                @can('update', $category)
                                <a href="{{ route('categories.edit', $category) }}" style="font-size:0.75rem;font-weight:600;color:#059669;text-decoration:none;margin-right:0.75rem;">Edit</a>
                                @endcan
                                @can('delete', $category)
                                <form method="POST" action="{{ route('categories.destroy', $category) }}" style="display:inline;" onsubmit="return confirm('Delete this category? Its budgets are deleted too; transactions keep it in their history.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" style="font-size:0.75rem;font-weight:600;color:#f87171;background:none;border:none;cursor:pointer;padding:0;">Delete</button>
                                </form>
                                @endcan
                            </td>
                            @endif
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div style="padding:3rem 1.5rem;text-align:center;">
                <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);display:block;margin-bottom:0.75rem;">sell</span>
                <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No categories yet.</p>
            </div>
            @endif
        </div>

        @if($canAdd)
        <div class="dot-card" style="padding:1.4rem 1.5rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0 0 1rem;">Add Category</h3>

            @if ($errors->any())
            <div style="margin-bottom:1rem;padding:0.6rem 0.8rem;border-radius:0.5rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.75rem;">
                <ul style="margin:0;padding-left:1rem;">
                    @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <form method="POST" action="{{ route('categories.store') }}" style="display:flex;flex-direction:column;gap:0.75rem;">
                @csrf
                <div>
                    <label for="categories-index-name" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Name</label>
                    <input id="categories-index-name" type="text" name="name" value="{{ old('name') }}" required maxlength="255" class="dot-input" placeholder="e.g. Groceries" />
                </div>
                <div>
                    <label for="categories-index-type" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Type</label>
                    <select id="categories-index-type" name="type" required class="dot-input">
                        <option value="expense" @selected(old('type', 'expense') === 'expense')>Expense</option>
                        <option value="income" @selected(old('type') === 'income')>Income</option>
                    </select>
                </div>
                <div>
                    <label for="categories-index-color" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Color</label>
                    <input id="categories-index-color" type="color" name="color" value="{{ old('color', '#6366f1') }}" style="height:36px;width:100%;padding:2px;border-radius:8px;border:1px solid var(--input-border);background:var(--input-bg);" />
                </div>
                <button type="submit" class="dot-btn dot-btn-primary" style="justify-content:center;margin-top:0.25rem;">Add Category</button>
            </form>
        </div>
        @endif

    </div>

</div>

</x-app-layout>
