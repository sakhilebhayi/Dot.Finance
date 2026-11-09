<x-app-layout title="Payroll tax summaries">
    <x-fx.page-head title="Payroll tax summaries" sub="PAYE, UIF, SDL and ETI for each month, captured here or imported from Dot.HR. Totals only; no pay slips." />
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    <x-fx.panel title="Months" flush>
        @if ($runs->isEmpty())<x-fx.empty title="No payroll summaries yet.">Capture the month's totals from your payroll system. Dot.HR does not hold payroll today, so there is no import to wait for.</x-fx.empty>@else
        <x-fx.table caption="Payroll tax summaries by month">
            <thead><tr><th scope="col">Month</th><th scope="col">Gross pay</th><th scope="col">PAYE</th><th scope="col">UIF</th><th scope="col">SDL</th><th scope="col">ETI</th><th scope="col">Status</th></tr></thead>
            <tbody>
            @foreach ($runs as $r)
                <tr>
                    <td data-label="Month" class="is-lead"><a class="fx-link" href="{{ route('tax.payroll.show', $r) }}">{{ $r->period_month->format('F Y') }}</a><span class="fx-cell-sub"><x-tax.basis :basis="$r->source === 'hr_import' ? 'calculated' : 'entered'" /> {{ $r->source === 'hr_import' ? 'imported from Dot.HR' : 'entered by hand' }}</span></td>
                    <td data-label="Gross pay" class="is-num"><x-fx.money :cents="$r->gross_pay_cents" currency="ZAR" /></td>
                    <td data-label="PAYE" class="is-num"><x-fx.money :cents="$r->paye_cents" currency="ZAR" /></td>
                    <td data-label="UIF" class="is-num"><x-fx.money :cents="$r->uif_employee_cents + $r->uif_employer_cents" currency="ZAR" /></td>
                    <td data-label="SDL" class="is-num"><x-fx.money :cents="$r->sdl_cents" currency="ZAR" /></td>
                    <td data-label="ETI" class="is-num"><x-fx.money :cents="$r->eti_claimed_cents" currency="ZAR" /></td>
                    <td data-label="Status"><x-fx.pill :kind="$r->isPosted() ? 'ticked' : 'plain'">{{ $r->isPosted() ? 'Posted' : 'Draft' }}</x-fx.pill></td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @endif
    </x-fx.panel>

    @if ($canPrepare)
    <x-fx.panel title="Capture a month" meta="Leave UIF and SDL blank to have them calculated from gross pay">
        <form method="POST" action="{{ route('tax.payroll.store') }}" class="fx-form fx-stack">@csrf
            <div class="fx-row fx-row--3">
                <x-fx.input name="month" label="Month" type="month" :value="$month->format('Y-m')" />
                <x-fx.input name="gross_pay" label="Gross pay (ZAR)" figure />
                <x-fx.input name="paye" label="PAYE withheld (ZAR)" figure />
                <x-fx.input name="uif_employee" label="Employee UIF (ZAR)" figure optional />
                <x-fx.input name="uif_employer" label="Employer UIF (ZAR)" figure optional />
                <x-fx.input name="sdl" label="SDL (ZAR)" figure optional />
                <x-fx.input name="eti" label="ETI claimed (ZAR)" figure optional />
                <x-fx.input name="employee_count" label="Employees paid" type="number" value="0" />
            </div>
            <x-fx.input name="notes" label="Notes" optional />
            <p class="fx-hint">A calculated UIF is on the whole payroll; the ceiling really applies per employee, so check it against your payroll system. The summary is saved as a draft; you post the journal on the next screen.</p>
            <div class="fx-formactions"><x-fx.button variant="primary">Save the month</x-fx.button></div>
        </form>
    </x-fx.panel>
    @endif
    @include('tax._footer')
</x-app-layout>
