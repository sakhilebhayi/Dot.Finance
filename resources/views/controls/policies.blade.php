<x-app-layout title="Approval policies">
<x-fx.page-head title="Approval policies" sub="Nothing needs approval until you turn a policy on. A policy says which amounts need a second person, who may approve, and how many different people must." />

@include('controls._nav')

<x-fx.panel title="Turned on" meta="{{ $policies->count() }} {{ Str::plural('policy', $policies->count()) }}">
    @if ($policies->isEmpty())
    <x-fx.empty title="Nothing needs approval yet.">No approval policies. Everything can be issued, posted and paid without a second person. Pick a preset below to start.</x-fx.empty>
    @else
    <ul class="fx-lines">
        @foreach ($policies as $policy)
        <li class="fx-line fx-line--split">
            <div class="fx-line__main">
                <p class="fx-line__title">{{ $policy->name }} @unless ($policy->is_active) <x-fx.pill kind="plain" icon="pause">off</x-fx.pill> @else <x-fx.pill kind="plain" icon="shield-check">on</x-fx.pill> @endunless</p>
                <p class="fx-line__sub">{{ $policy->summary() }}@if ($policy->allow_self_approval) Self-approval allowed while the ledger has one member. @endif</p>
            </div>
            @if ($canManage)
            <div class="fx-line__side">
                <form method="POST" action="{{ route('controls.policies.update', $policy) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="is_active" value="{{ $policy->is_active ? 0 : 1 }}">
                    <x-fx.button size="sm" :icon="$policy->is_active ? 'pause' : 'play'">{{ $policy->is_active ? 'Turn off' : 'Turn on' }}<span class="sr-only"> {{ $policy->name }}</span></x-fx.button>
                </form>
                <form method="POST" action="{{ route('controls.policies.destroy', $policy) }}" data-fx-confirm="Remove the policy &quot;{{ $policy->name }}&quot;? Requests already waiting keep the rules they were made under." data-fx-confirm-label="Remove policy">
                    @csrf
                    @method('DELETE')
                    <x-fx.button variant="ghost" size="sm" icon="trash-2">Remove<span class="sr-only"> {{ $policy->name }}</span></x-fx.button>
                </form>
            </div>
            @endif
        </li>
        @endforeach
    </ul>
    @endif
</x-fx.panel>

@if ($canManage)
<x-fx.panel title="One-click presets" meta="The usual set-ups for a small business. Turn one on and change the amount if you like.">
    <ul class="fx-lines">
        @foreach ($presets as $key => $preset)
        <li class="fx-line fx-line--form">
            <form method="POST" action="{{ route('controls.policies.preset') }}" class="fx-preset">
                @csrf
                <input type="hidden" name="preset" value="{{ $key }}">
                <div class="fx-line__main">
                    <h3 class="fx-line__title">{{ \App\Services\Controls\ApprovalPresets::name($key, $preset['takes_amount'] ? $preset['default_cents'] : null, $currency) }}</h3>
                    <p class="fx-line__sub">{{ $preset['summary'] }}@if ($preset['steps'] > 1) {{ $preset['steps'] }} different people are needed. @endif</p>
                </div>
                <div class="fx-preset__controls">
                    @if ($preset['takes_amount'])
                    <x-fx.input name="amount" id="preset-{{ $key }}-amount" label="Over this amount" inputmode="decimal" :value="\App\Support\Money::toDecimalString($preset['default_cents'])" :bind="false" figure />
                    <x-fx.input name="currency" id="preset-{{ $key }}-currency" label="Currency" maxlength="3" :value="$currency" :bind="false" />
                    @endif
                    <x-fx.button variant="primary" icon="shield-check">Turn on<span class="sr-only"> {{ $key }}</span></x-fx.button>
                </div>
            </form>
        </li>
        @endforeach
    </ul>
</x-fx.panel>

<details class="fx-panel fx-more" @if ($errors->any()) open @endif>
    <summary class="fx-more__summary"><span><span class="fx-more__title">Custom policy</span><span class="fx-more__sub">Write your own rule if none of the presets fit.</span></span><x-fx.icon name="chevron-down" /></summary>
    <form method="POST" action="{{ route('controls.policies.store') }}" class="fx-form fx-more__body">
        @csrf
        <div class="fx-row fx-row--2">
            <x-fx.select name="subject_type" id="custom-subject" label="Covers" required>
                @foreach ($subjects as $subject)
                <option value="{{ $subject->value }}">{{ $subject->label() }}</option>
                @endforeach
            </x-fx.select>
            <x-fx.input name="name" id="custom-name" label="Name" optional maxlength="160" />
        </div>
        <div class="fx-row fx-row--2">
            <x-fx.input name="amount" id="custom-amount" label="Needs approval over" optional inputmode="decimal" figure hint="Leave empty for every amount." />
            <x-fx.input name="currency" id="custom-currency" label="Currency" maxlength="3" :value="$currency" />
        </div>
        <div class="fx-row fx-row--2">
            <x-fx.select name="min_role" id="custom-role" label="Approver must be at least">
                <option value="">Anyone with the right permission</option>
                @foreach ($roles as $role)
                <option value="{{ $role->value }}">{{ $role->label() }}</option>
                @endforeach
            </x-fx.select>
            <x-fx.select name="steps_required" id="custom-steps" label="Different people who must approve">
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
            </x-fx.select>
        </div>
        <x-fx.check name="allow_self_approval" id="custom-self">Let the only member of a ledger approve their own requests</x-fx.check>
        <div class="fx-formactions"><x-fx.button variant="primary" icon="plus">Add policy</x-fx.button></div>
    </form>
</details>
@endif
</x-app-layout>
