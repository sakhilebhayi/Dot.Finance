@props(['action', 'name' => 'months', 'options' => [], 'selected' => null, 'label' => 'Period', 'suffix' => ''])
{{-- The period selector of a page header: a GET form, so the choice lives in the URL. It submits on change when
     scripting is on; the button is the fallback and is hidden then. --}}
<form method="GET" action="{{ $action }}" class="fx-cluster" aria-label="{{ $label }}">
    <label for="fx-period-{{ $name }}" class="fx-label">{{ $label }}</label>
    <select id="fx-period-{{ $name }}" name="{{ $name }}" class="fx-select" data-fx-autosubmit>
        @foreach ($options as $value => $text)
        <option value="{{ $value }}" @selected((string) $selected === (string) $value)>{{ $text }}{{ $suffix }}</option>
        @endforeach
    </select>
    <noscript><button type="submit" class="fx-btn fx-btn--sm">Show</button></noscript>
</form>
