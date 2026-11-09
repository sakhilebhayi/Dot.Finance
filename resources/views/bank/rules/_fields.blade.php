@php $r = $rule; $dec = fn (?int $c) => $c === null ? null : \App\Support\Money::toDecimalString($c); @endphp
<div class="fx-row fx-row--3">
    <x-fx.input name="name" id="bank-rule-name" label="Name" required :value="$r?->name" maxlength="80" />
    <x-fx.select name="account_id" id="bank-rule-account" label="Applies to" optional>
        <option value="">Every account</option>
        @foreach ($accounts as $account)<option value="{{ $account->id }}" @selected((string) old('account_id', $r?->account_id) === (string) $account->id)>{{ $account->name }}</option>@endforeach
    </x-fx.select>
    <x-fx.select name="direction" id="bank-rule-direction" label="Money">
        @foreach (\App\Models\Bank\BankRule::DIRECTIONS as $value => $label)<option value="{{ $value }}" @selected(old('direction', $r?->direction ?? 'any') === $value)>{{ $label }}</option>@endforeach
    </x-fx.select>
</div>
<div class="fx-row fx-row--3">
    <x-fx.select name="match_field" id="bank-rule-field" label="If the">
        @foreach (\App\Models\Bank\BankRule::FIELDS as $value => $label)<option value="{{ $value }}" @selected(old('match_field', $r?->match_field ?? 'any') === $value)>{{ $label }}</option>@endforeach
    </x-fx.select>
    <x-fx.select name="operator" id="bank-rule-operator" label="Reads">
        @foreach (\App\Models\Bank\BankRule::OPERATORS as $value => $label)<option value="{{ $value }}" @selected(old('operator', $r?->operator ?? 'contains') === $value)>{{ $label }}</option>@endforeach
    </x-fx.select>
    <x-fx.input name="pattern" id="bank-rule-pattern" label="These words" required :value="$r?->pattern" maxlength="120" hint="Capitals and spacing do not matter." />
</div>
<div class="fx-row fx-row--3">
    <x-fx.select name="category_id" id="bank-rule-category" label="Suggest the category" optional>
        <option value="">No category</option>
        @foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', $r?->category_id) === (string) $category->id)>{{ $category->name }} ({{ $category->type }})</option>@endforeach
    </x-fx.select>
    <x-fx.input name="min_amount" id="bank-rule-min" label="Only from this amount" optional figure :value="$dec($r?->min_cents)" placeholder="0.00" />
    <x-fx.input name="max_amount" id="bank-rule-max" label="Only up to this amount" optional figure :value="$dec($r?->max_cents)" placeholder="0.00" />
</div>
<div class="fx-row fx-row--3">
    <x-fx.input name="new_description" id="bank-rule-description" label="Describe the entry as" optional :value="$r?->new_description" maxlength="120" hint="Left blank, the entry keeps the bank's wording." />
    <x-fx.input name="priority" id="bank-rule-priority" label="Order" type="number" optional :value="$r?->priority ?? 100" min="1" max="9999" hint="Lowest number is tried first." />
    <x-fx.check name="is_active" id="bank-rule-active" :checked="$r?->is_active ?? true">Switched on</x-fx.check>
</div>
