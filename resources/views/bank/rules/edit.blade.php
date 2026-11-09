<x-app-layout title="Edit bank rule">
<x-fx.page-head :title="'Edit rule: '.$rule->name" />
@include('bank._tabs')
<x-fx.errors />
<form method="POST" action="{{ route('bank.rules.update', $rule) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf @method('PUT')
    @include('bank.rules._fields', ['rule' => $rule])
    <div class="fx-formactions">
        <x-fx.button :href="route('bank.rules.index')">Cancel</x-fx.button>
        <x-fx.button variant="primary">Save</x-fx.button>
    </div>
</form>
</x-app-layout>
