<x-app-layout>
<div class="page-pad">
    <h1>New manual journal entry</h1>
    @include('accounting._nav')
    @include('accounting._messages')

    <form method="POST" action="{{ route('accounting.journals.store') }}" class="dot-card acct-form">
        @csrf
        <p>
            <label for="entry_date">Date</label>
            <input id="entry_date" type="date" name="entry_date" class="dot-input" required value="{{ old('entry_date', now()->toDateString()) }}">
        </p>
        <p>
            <label for="memo">Memo</label>
            <input id="memo" name="memo" class="dot-input" required maxlength="500" value="{{ old('memo') }}">
        </p>
        <table>
            <caption class="sr-only">Entry lines: debits must equal credits</caption>
            <thead><tr><th scope="col">Account</th><th scope="col">Debit</th><th scope="col">Credit</th><th scope="col">Description</th></tr></thead>
            <tbody>
            @for ($i = 0; $i < $lineCount; $i++)
                <tr>
                    <td>
                        <label class="sr-only" for="line-{{ $i }}-account">Account, line {{ $i + 1 }}</label>
                        <select id="line-{{ $i }}-account" name="lines[{{ $i }}][account_id]" class="dot-input">
                            <option value="">Unused</option>
                            @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) old("lines.$i.account_id") === $a->id)>{{ $a->label() }}</option>@endforeach
                        </select>
                    </td>
                    <td><label class="sr-only" for="line-{{ $i }}-debit">Debit, line {{ $i + 1 }}</label><input id="line-{{ $i }}-debit" name="lines[{{ $i }}][debit]" class="dot-input" inputmode="decimal" value="{{ old("lines.$i.debit") }}"></td>
                    <td><label class="sr-only" for="line-{{ $i }}-credit">Credit, line {{ $i + 1 }}</label><input id="line-{{ $i }}-credit" name="lines[{{ $i }}][credit]" class="dot-input" inputmode="decimal" value="{{ old("lines.$i.credit") }}"></td>
                    <td><label class="sr-only" for="line-{{ $i }}-desc">Description, line {{ $i + 1 }}</label><input id="line-{{ $i }}-desc" name="lines[{{ $i }}][description]" class="dot-input" maxlength="255" value="{{ old("lines.$i.description") }}"></td>
                </tr>
            @endfor
            </tbody>
        </table>
        <p class="acct-hint">Use at least two lines. Total debits must equal total credits; the server checks, not the browser. The date must be in an open period. Cash accounts are not offered here: record the movement as a transaction or transfer.</p>
        <p><button type="submit" class="dot-btn">Post entry</button> <a href="{{ route('accounting.journals.index') }}">Cancel</a></p>
    </form>
</div>
</x-app-layout>
