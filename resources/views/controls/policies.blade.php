<x-app-layout>
<div class="page-pad controls-page">
    <h1>Approval policies</h1>
    <p>Nothing needs approval until you turn a policy on. A policy says which amounts need a second person, who may approve, and how many different people must.</p>
    @include('controls._nav')

    <h2>Turned on</h2>
    @forelse ($policies as $policy)
        <article class="controls-policy">
            <h3>{{ $policy->name }} @unless ($policy->is_active) <small>(off)</small> @endunless</h3>
            <p>{{ $policy->summary() }}@if ($policy->allow_self_approval) Self-approval allowed while the ledger has one member. @endif</p>
            @if ($canManage)
                <form method="POST" action="{{ route('controls.policies.update', $policy) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="is_active" value="{{ $policy->is_active ? 0 : 1 }}">
                    <button type="submit" class="dot-btn">{{ $policy->is_active ? 'Turn off' : 'Turn on' }}</button>
                </form>
                <form method="POST" action="{{ route('controls.policies.destroy', $policy) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="dot-btn">Remove</button>
                </form>
            @endif
        </article>
    @empty
        <p>No approval policies. Everything can be issued, posted and paid without a second person. Pick a preset below to start.</p>
    @endforelse

    @if ($canManage)
        <h2>One-click presets</h2>
        @foreach ($presets as $key => $preset)
            <form method="POST" action="{{ route('controls.policies.preset') }}" class="controls-preset">
                @csrf
                <input type="hidden" name="preset" value="{{ $key }}">
                <h3>{{ \App\Services\Controls\ApprovalPresets::name($key, $preset['takes_amount'] ? $preset['default_cents'] : null, $currency) }}</h3>
                <p>{{ $preset['summary'] }}@if ($preset['steps'] > 1) {{ $preset['steps'] }} different people are needed. @endif</p>
                @if ($preset['takes_amount'])
                    <label for="preset-{{ $key }}-amount">Over this amount</label>
                    <input id="preset-{{ $key }}-amount" name="amount" inputmode="decimal" class="dot-input" value="{{ \App\Support\Money::toDecimalString($preset['default_cents']) }}">
                    <label for="preset-{{ $key }}-currency">Currency</label>
                    <input id="preset-{{ $key }}-currency" name="currency" maxlength="3" class="dot-input" value="{{ $currency }}">
                @endif
                <button type="submit" class="dot-btn dot-btn-primary">Turn on</button>
            </form>
        @endforeach

        <h2>Custom policy</h2>
        <form method="POST" action="{{ route('controls.policies.store') }}" class="controls-custom">
            @csrf
            <label for="custom-subject">Covers</label>
            <select id="custom-subject" name="subject_type" class="dot-input" required>
                @foreach ($subjects as $subject)
                    <option value="{{ $subject->value }}">{{ $subject->label() }}</option>
                @endforeach
            </select>
            <label for="custom-name">Name (optional)</label>
            <input id="custom-name" name="name" maxlength="160" class="dot-input">
            <label for="custom-amount">Needs approval over (leave empty for every amount)</label>
            <input id="custom-amount" name="amount" inputmode="decimal" class="dot-input">
            <label for="custom-currency">Currency</label>
            <input id="custom-currency" name="currency" maxlength="3" class="dot-input" value="{{ $currency }}">
            <label for="custom-role">Approver must be at least</label>
            <select id="custom-role" name="min_role" class="dot-input">
                <option value="">Anyone with the right permission</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                @endforeach
            </select>
            <label for="custom-steps">Different people who must approve</label>
            <select id="custom-steps" name="steps_required" class="dot-input">
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
            </select>
            <label><input type="checkbox" name="allow_self_approval" value="1"> Let the only member of a ledger approve their own requests</label>
            <button type="submit" class="dot-btn dot-btn-primary">Add policy</button>
        </form>
    @endif
</div>
</x-app-layout>
