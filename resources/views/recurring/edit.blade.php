<x-app-layout title="Edit recurring item">

<x-fx.page-head :title="'Edit recurring '.$rule->type" :sub="'On '.($rule->account?->name ?? 'its account').' in '.$rule->currency.'. Changes apply to entries added from now on; entries already in your ledger are not rewritten.'" />

<x-fx.errors />

<form method="POST" action="{{ route('recurring.update', $rule) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    @method('PUT')

    <x-fx.input name="description" id="recurring-edit-description" label="Description" :value="$rule->description" required maxlength="255" />

    <div class="fx-row fx-row--2">
        <x-fx.input name="amount" id="recurring-edit-amount" label="Amount ({{ $rule->currency }})" inputmode="decimal" :value="$rule->amount" required figure />
        <x-fx.select name="category_id" id="recurring-edit-category-id" label="Category" optional>
            <option value="">Uncategorized</option>
            @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected((string) old('category_id', $rule->category_id) === (string) $category->id)>{{ $category->name }}</option>
            @endforeach
        </x-fx.select>
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.select name="recurrence_type" id="recurring-edit-recurrence-type" label="Repeats">
            @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
            <option value="{{ $value }}" @selected(old('recurrence_type', $rule->recurrence_type) === $value)>{{ $label }}</option>
            @endforeach
        </x-fx.select>
        <x-fx.input name="recurrence_interval" id="recurring-edit-recurrence-interval" type="number" label="Every" min="1" max="365" :value="$rule->recurrence_interval" required figure />
    </div>
    <p class="fx-hint">Changing how often it repeats counts from the last entry already in your ledger, and the next date is never in the past. Currently next on <strong class="fx-date">{{ $rule->next_occurrence_date?->format('Y-m-d') ?? 'no further date' }}</strong>.</p>

    <x-fx.input name="notes" id="recurring-edit-notes" label="Notes" optional maxlength="255" :value="$rule->notes" />

    <div class="fx-formactions">
        <x-fx.button variant="primary">Save changes</x-fx.button>
        <x-fx.button :href="route('recurring.index')">Cancel</x-fx.button>
    </div>
</form>

<form method="POST" action="{{ route('recurring.stop', $rule) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    <x-fx.input name="end_date" id="recurring-edit-end-date" type="date" label="End on" optional hint="Entries up to and including this date are still added. Leave empty to stop today." />
    <div class="fx-formactions"><x-fx.button variant="danger">Stop recurring</x-fx.button></div>
</form>

</x-app-layout>
