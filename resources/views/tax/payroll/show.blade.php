<x-app-layout :title="'Payroll '.$run->period_month->format('F Y')">
    <x-fx.page-head :title="'Payroll taxes, '.$run->period_month->format('F Y')" :sub="($run->source === 'hr_import' ? 'Imported from Dot.HR' : 'Entered by hand').', '.($run->isPosted() ? 'posted to the books' : 'a draft')">
        <x-slot name="actions"><x-fx.button :href="route('tax.payroll.index')">All months</x-fx.button></x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    <x-fx.panel title="The month">
        <x-fx.statement>
            <x-fx.statement-row label="Gross pay"><x-fx.money :cents="$run->gross_pay_cents" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="PAYE withheld"><x-fx.money :cents="$run->paye_cents" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="UIF, employee"><x-fx.money :cents="$run->uif_employee_cents" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="UIF, employer"><x-fx.money :cents="$run->uif_employer_cents" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="SDL"><x-fx.money :cents="$run->sdl_cents" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="ETI claimed"><x-fx.money :cents="$run->eti_claimed_cents" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="Owed to SARS on the monthly employer return (EMP201)" kind="total"><x-fx.money :cents="$run->emp201LiabilityCents()" currency="ZAR" lead /></x-fx.statement-row>
        </x-fx.statement>
        <x-tax.basis :basis="$run->source === 'hr_import' ? 'calculated' : 'entered'" />
        @if ($run->notes)<p class="fx-hint">{{ $run->notes }}</p>@endif
    </x-fx.panel>

    <x-fx.panel title="Employees" meta="Sensitive detail">
        @if (! $canDetail)
            <p class="fx-hint">{{ $lineCount }} {{ $lineCount === 1 ? 'employee line is' : 'employee lines are' }} held. Your role cannot see payroll detail, so names and individual amounts are not shown. Ask the owner if you need access.</p>
        @elseif ($lines->isEmpty())<p class="fx-hint">No per-employee lines were supplied: this is a summary only.</p>
        @else
        <x-fx.table caption="Per-employee payroll tax lines">
            <thead><tr><th scope="col">Employee</th><th scope="col">Gross</th><th scope="col">PAYE</th><th scope="col">UIF (employee)</th><th scope="col">SDL</th></tr></thead>
            <tbody>@foreach ($lines as $l)<tr><td data-label="Employee" class="is-lead">{{ $l->employee_ref }}<span class="fx-cell-sub">{{ $l->employee_name }}</span></td><td data-label="Gross" class="is-num"><x-fx.money :cents="$l->gross_cents" currency="ZAR" /></td><td data-label="PAYE" class="is-num"><x-fx.money :cents="$l->paye_cents" currency="ZAR" /></td><td data-label="UIF (employee)" class="is-num"><x-fx.money :cents="$l->uif_employee_cents" currency="ZAR" /></td><td data-label="SDL" class="is-num"><x-fx.money :cents="$l->sdl_cents" currency="ZAR" /></td></tr>@endforeach</tbody>
        </x-fx.table>
        @endif
    </x-fx.panel>

    <x-fx.panel title="Payroll journal" :meta="$run->isPosted() ? 'Posted' : 'Suggested, not posted until you confirm'">
        @if ($run->isPosted())
            <p>Posted on {{ $run->posted_at->format('j M Y H:i') }} as <a class="fx-link" href="{{ route('accounting.journals.show', $run->journal_entry_id) }}">a journal entry</a>. This summary is locked and is never overwritten.</p>
        @else
            <x-fx.table caption="Suggested payroll journal">
                <thead><tr><th scope="col">Account</th><th scope="col">Debit</th><th scope="col">Credit</th></tr></thead>
                <tbody>@foreach ($suggested as $l)<tr><td data-label="Account" class="is-lead">{{ ucfirst(str_replace('_', ' ', $l['account'])) }}<span class="fx-cell-sub">{{ $l['label'] }}</span></td><td data-label="Debit" class="is-num">@if($l['debit_cents'])<x-fx.money :cents="$l['debit_cents']" currency="ZAR" />@endif</td><td data-label="Credit" class="is-num">@if($l['credit_cents'])<x-fx.money :cents="$l['credit_cents']" currency="ZAR" />@endif</td></tr>@endforeach</tbody>
            </x-fx.table>
            <p class="fx-hint">Net pay is paid from the bank and is recorded as a bank transaction categorised to salaries. This journal adds the tax and UIF withheld and the employer's contributions, so salaries expense equals gross pay plus employer contributions. If you do not record net pay in the bank, salaries expense will be understated.</p>
            <x-tax.basis basis="suggestion" />
            @if ($canPost)
            <form method="POST" action="{{ route('tax.payroll.post', $run) }}" class="fx-form fx-stack">@csrf
                <x-fx.check name="confirm">I confirm these amounts and want the journal posted</x-fx.check>
                <div class="fx-formactions"><x-fx.button variant="primary">Post the payroll journal</x-fx.button></div>
            </form>
            @endif
        @endif
    </x-fx.panel>
    @include('tax._footer')
</x-app-layout>
