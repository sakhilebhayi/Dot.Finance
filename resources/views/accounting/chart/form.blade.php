<x-app-layout>
<div class="page-pad">
    <h1>{{ $account->exists ? 'Edit account '.$account->code : 'Add an account' }}</h1>
    @include('accounting._nav')
    @include('accounting._messages')

    <form method="POST" action="{{ $account->exists ? route('accounting.chart.update', $account) : route('accounting.chart.store') }}" class="dot-card acct-form">
        @csrf
        @if ($account->exists) @method('PUT') @endif
        <p>
            <label for="code">Code</label>
            <input id="code" name="code" class="dot-input" required maxlength="20" value="{{ old('code', $account->code) }}">
        </p>
        <p>
            <label for="name">Name</label>
            <input id="name" name="name" class="dot-input" required maxlength="120" value="{{ old('name', $account->name) }}">
        </p>
        <p>
            <label for="class">Class</label>
            @if (($locked ?? false))
                <input id="class" class="dot-input" value="{{ ucfirst($account->class) }}" disabled>
                <span class="acct-hint">The class is fixed once an account has postings or the engine uses it.</span>
            @else
                <select id="class" name="class" class="dot-input">
                    @foreach ($classes as $class)
                        <option value="{{ $class }}" @selected(old('class', $account->class) === $class)>{{ ucfirst($class) }}</option>
                    @endforeach
                </select>
            @endif
        </p>
        <p>
            <label for="parent_id">Parent account</label>
            <select id="parent_id" name="parent_id" class="dot-input">
                <option value="">None</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}" @selected((int) old('parent_id', $account->parent_id) === $parent->id)>{{ $parent->label() }}</option>
                @endforeach
            </select>
        </p>
        <p>
            <label for="description">Description</label>
            <input id="description" name="description" class="dot-input" maxlength="255" value="{{ old('description', $account->description) }}">
        </p>
        <p><button type="submit" class="dot-btn">Save</button> <a href="{{ route('accounting.chart.index') }}">Cancel</a></p>
    </form>
</div>
</x-app-layout>
