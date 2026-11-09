@php
    $isVat = $return->kind === 'vat201';
    $tone = $return->isSubmitted() ? 'ticked' : 'plain';
    $c = $frozen ? $liveChecks : $checks;
    $openReview = collect($review)->where('resolved', false)->count();
    $reviewUrl = route('tax.vat.review', ['from' => $return->period_start->toDateString(), 'to' => $return->period_end->toDateString()]);
@endphp
<x-app-layout :title="$return->kindLabel().' '.$return->period_label">
    <x-fx.page-head :title="$return->kindLabel().' for '.$return->period_label" :sub="$return->period_start->format('j M Y').' to '.$return->period_end->format('j M Y').($return->revision > 1 ? ', revision '.$return->revision : '')">
        <x-slot name="actions">
            @if ($canExport)
                <x-fx.button :href="route('tax.returns.workpaper', $return)" icon="file-text">Working paper</x-fx.button>
                <x-fx.button :href="route('tax.returns.csv', $return)" icon="download">CSV</x-fx.button>
            @endif
        </x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    <div class="tax-banner" role="status">
        <x-fx.pill :kind="$tone">{{ $return->statusLabel() }}</x-fx.pill>
        <p>
            @if ($return->isSubmitted())
                A person recorded this as submitted on {{ $return->submitted_on->format('j F Y') }}, SARS reference <strong>{{ $return->sars_reference }}</strong>. Dot.Finance did not submit it. It is frozen.
            @elseif ($frozen)
                {{ \App\Services\Tax\TaxBasis::NOT_SUBMITTED }}
            @else
                Draft. These figures are recomputed from the books each time you open this page and can still change. Nothing has been submitted to SARS.
            @endif
        </p>
    </div>
    @if ($superseded)<div class="fx-alert" role="status"><x-fx.icon name="info" /><p>A later revision of this period exists. This one stays exactly as it was.</p></div>@endif
    @if ($frozen && $integrity === false)<div class="fx-alert" role="alert"><x-fx.icon name="circle-alert" /><p>The frozen figures no longer match their hash. Do not rely on this return.</p></div>@endif

    {{-- checks --}}
    @if (! $return->isSubmitted())
    <x-fx.panel :title="$c['ready'] ? 'Ready to prepare' : 'Not ready to prepare'" meta="Calculated from the books today">
        @if (! $c['ready'])<ul class="fx-list">@foreach ($c['blockers'] as $b)<li><x-fx.pill kind="variance">Blocks</x-fx.pill> {{ $b }}</li>@endforeach</ul>@endif
        @if ($c['warnings'])<ul class="fx-list">@foreach ($c['warnings'] as $w)<li><x-fx.pill>Note</x-fx.pill> {{ $w }}</li>@endforeach</ul>@endif
        @if ($c['ready'] && ! $c['warnings'])<p><x-fx.icon name="check" class="fx-tick-draw" /> Every check passes.</p>@endif
        @if ($isVat && $openReview > 0)
        <x-slot name="foot">
            <a class="fx-link" href="{{ $reviewUrl }}">Open the review queue ({{ $openReview }} open)</a>
        </x-slot>
        @endif
    </x-fx.panel>
    @endif

    {{-- boxes --}}
    <x-fx.panel :title="$isVat ? 'The return, field by field' : 'The declaration'" :meta="$isVat ? 'SARS VAT201 layout, revision 11. Fields 1 and 1A include VAT.' : 'PAYE, SDL, UIF and ETI for the month'" flush>
        <x-fx.table caption="Return fields with the amount in each">
            <thead><tr><th scope="col">Field</th><th scope="col">What it is</th><th scope="col">Amount</th><th scope="col">Behind it</th></tr></thead>
            <tbody>
            @foreach ($boxes as $b)
                <tr>
                    <td data-label="Field" class="is-lead"><span class="tax-box">{{ $b['code'] }}</span></td>
                    <td data-label="What it is">{{ $b['label'] }}@if($b['note'] ?? null)<span class="fx-cell-sub">{{ $b['note'] }}</span>@endif @if(($b['kind'] ?? '') === 'calc')<x-tax.basis basis="calculated" />@endif</td>
                    <td data-label="Amount" class="is-num"><x-fx.money :cents="$b['amount_cents']" currency="ZAR" /></td>
                    <td data-label="Behind it">
                        @if (count($b['contributors']))
                        <details class="tax-drill"><summary>{{ count($b['contributors']) }} line{{ count($b['contributors']) === 1 ? '' : 's' }}</summary>
                            <ul class="fx-list">
                            @foreach ($b['contributors'] as $k)
                                <li>
                                    @if ($k['entry_id'] && $k['type'] === 'line')<a class="fx-link" href="{{ route('accounting.journals.show', $k['entry_id']) }}">JE-{{ str_pad((string) $k['entry_number'], 6, '0', STR_PAD_LEFT) }}</a> {{ $k['date'] }}@else {{ ucfirst($k['type']) }}@endif
                                    , {{ $k['description'] }}: <x-fx.money :cents="$k['amount_cents']" currency="ZAR" />@if($k['type'] === 'adjustment') <x-tax.basis basis="entered" />@endif
                                </li>
                            @endforeach
                            </ul>
                        </details>
                        @elseif (($b['kind'] ?? '') === 'calc')<span class="fx-hint">{{ $b['formula'] }}</span>@else<span class="fx-hint">none</span>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        <x-slot name="foot">
            <x-fx.statement>
                <x-fx.statement-row :label="$isVat ? 'Output tax' : 'PAYE, SDL and UIF'"><x-fx.money :cents="$totals['output']" currency="ZAR" /></x-fx.statement-row>
                <x-fx.statement-row :label="$isVat ? 'Input tax' : 'ETI claimed'"><x-fx.money :cents="$totals['input']" currency="ZAR" /></x-fx.statement-row>
                <x-fx.statement-row :label="$totals['payable'] >= 0 ? 'Payable to SARS' : 'Refundable from SARS'" kind="total"><x-fx.money :cents="abs($totals['payable'])" currency="ZAR" lead /></x-fx.statement-row>
            </x-fx.statement>
        </x-slot>
    </x-fx.panel>

    {{-- reconciliation --}}
    @if ($isVat && isset($reconciliation['gl_net_cents']))
    <x-fx.panel title="Reconciled to the VAT accounts" :meta="($reconciliation['ties'] ?? false) ? 'Ties out' : 'Does not tie out'">
        <x-fx.statement>
            <x-fx.statement-row label="Output VAT in the books"><x-fx.money :cents="$reconciliation['gl_output_cents']" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="Input VAT in the books"><x-fx.money :cents="$reconciliation['gl_input_cents']" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="Net VAT in the books" kind="subtotal"><x-fx.money :cents="$reconciliation['gl_net_cents']" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="Field 20 from the books alone"><x-fx.money :cents="$reconciliation['return_from_books_cents']" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="Difference"><x-fx.money :cents="$reconciliation['difference_cents']" currency="ZAR" /></x-fx.statement-row>
            @foreach ($reconciliation['explained'] ?? [] as $e)<x-fx.statement-row :label="'Explained: '.$e['label']"><x-fx.money :cents="$e['cents']" currency="ZAR" /></x-fx.statement-row>@endforeach
            <x-fx.statement-row label="Unexplained" kind="total"><x-fx.money :cents="$reconciliation['unexplained_cents']" currency="ZAR" :ticked="$reconciliation['ties'] ?? false" :variance="! ($reconciliation['ties'] ?? false)" /></x-fx.statement-row>
        </x-fx.statement>
        @if (($reconciliation['adjustments_cents'] ?? 0) !== 0)<p class="fx-hint">Your manual adjustments move field 20 by <x-fx.money :cents="$reconciliation['adjustments_cents']" currency="ZAR" signed />. They are in the return and, by design, not in the books.</p>@endif
        <x-tax.basis basis="calculated" />
    </x-fx.panel>
    @elseif (! $isVat && isset($reconciliation['rows']))
    <x-fx.panel title="Reconciled to the payroll liabilities in the books" :meta="($reconciliation['ties'] ?? false) ? 'Ties out' : (($reconciliation['posted'] ?? false) ? 'Does not tie out' : 'Payroll journal not posted')">
        <x-fx.table caption="Summary against the books">
            <thead><tr><th scope="col">Liability</th><th scope="col">Summary</th><th scope="col">Books</th><th scope="col">Difference</th></tr></thead>
            <tbody>@foreach ($reconciliation['rows'] as $r)<tr><td data-label="Liability" class="is-lead">{{ $r['label'] }}</td><td data-label="Summary" class="is-num"><x-fx.money :cents="$r['summary_cents']" currency="ZAR" /></td><td data-label="Books" class="is-num"><x-fx.money :cents="$r['gl_cents']" currency="ZAR" /></td><td data-label="Difference" class="is-num"><x-fx.money :cents="$r['difference_cents']" currency="ZAR" :variance="$r['difference_cents'] !== 0" /></td></tr>@endforeach</tbody>
        </x-fx.table>
    </x-fx.panel>
    @endif

    {{-- adjustments --}}
    @if ($isVat)
    <x-fx.panel title="Adjustments you entered" meta="Each needs a reason. They are added to the field as computed." flush>
        @if ($adjustments->isEmpty())<x-fx.empty title="None.">Bad debts, apportionment of mixed-use costs and similar go here.</x-fx.empty>@else
        <x-fx.table caption="Manual adjustments to the return">
            <thead><tr><th scope="col">Field</th><th scope="col">Kind</th><th scope="col">Amount</th><th scope="col">Reason</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
            <tbody>
            @foreach ($adjustments as $a)
                <tr>
                    <td data-label="Field" class="is-lead"><span class="tax-box">{{ $a->box_code }}</span></td>
                    <td data-label="Kind">{{ $adjustmentKinds[$a->kind] ?? $a->kind }}</td>
                    <td data-label="Amount" class="is-num"><x-fx.money :cents="$a->amount_cents" currency="ZAR" signed /></td>
                    <td data-label="Reason">{{ $a->reason }}@if($a->isVoided())<span class="fx-cell-sub">Voided: {{ $a->void_reason }}</span>@endif</td>
                    <td data-label="" class="is-actions">
                        @if ($return->isDraft() && $canPrepare && ! $a->isVoided())
                        <form method="POST" action="{{ route('tax.returns.adjust.void', [$return, $a]) }}" class="fx-cluster">@csrf
                            <label class="sr-only" for="v{{ $a->id }}">Reason for voiding</label><input id="v{{ $a->id }}" name="reason" class="fx-input" required minlength="3" placeholder="Why void it">
                            <x-fx.button size="sm">Void</x-fx.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @endif
        @if ($return->isDraft() && $canPrepare)
        <x-slot name="foot">
            <form method="POST" action="{{ route('tax.returns.adjust', $return) }}" enctype="multipart/form-data" class="fx-form fx-stack">
                @csrf
                <div class="fx-row fx-row--3">
                    <x-fx.select name="box_code" label="Field">@foreach ($adjustable as $code)<option value="{{ $code }}">{{ $code }}</option>@endforeach</x-fx.select>
                    <x-fx.select name="kind" label="Kind">@foreach ($adjustmentKinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</x-fx.select>
                    <x-fx.select name="direction" label="Effect on the field"><option value="increase">Adds to it</option><option value="decrease">Takes from it</option></x-fx.select>
                </div>
                <div class="fx-row fx-row--2">
                    <x-fx.input name="amount" label="Amount (ZAR)" figure hint="A positive amount, for example 450.00." />
                    <x-fx.input name="reason" label="Reason" hint="What happened, and which document shows it." />
                </div>
                <x-fx.input name="file" label="Supporting file" type="file" optional />
                <div class="fx-formactions"><x-fx.button>Add the adjustment</x-fx.button></div>
            </form>
        </x-slot>
        @endif
    </x-fx.panel>
    @endif

    {{-- lifecycle actions --}}
    <x-fx.panel title="Next step">
        <div class="fx-stack">
        @if ($return->isDraft())
            @if ($canPrepare)
            <form method="POST" action="{{ route('tax.returns.prepare', $return) }}" class="fx-form fx-stack">@csrf
                @if ($return->basis === 'payments')<x-fx.check name="acknowledge_payments_basis">I have adjusted these figures to the payments basis</x-fx.check>@endif
                <p class="fx-hint">Preparing freezes the figures with a hash and the rule versions used. It does not file anything.</p>
                <div class="fx-formactions"><x-fx.button variant="primary" :disabled="! $liveChecks['ready']">Prepare this return</x-fx.button></div>
            </form>
            @else
                <p class="fx-hint">Your role cannot prepare returns.</p>
            @endif
        @elseif ($return->isPrepared())
            <ol class="fx-list">@foreach ($instructions as $i)<li><strong>{{ $i['step'] }}</strong><p class="fx-hint">{{ $i['detail'] }}</p></li>@endforeach</ol>
            @if ($canRecord)
            <form method="POST" action="{{ route('tax.returns.submission', $return) }}" enctype="multipart/form-data" class="fx-form fx-stack">@csrf
                <h3>Record that you submitted it</h3>
                <div class="fx-row fx-row--2">
                    <x-fx.input name="sars_reference" label="SARS reference" hint="The reference eFiling or the branch gave you." />
                    <x-fx.input name="submitted_on" label="Date submitted" type="date" :value="$today->toDateString()" />
                </div>
                <x-fx.input name="proof" label="Proof of submission" type="file" optional />
                <x-fx.input name="note" label="Note" optional />
                <p class="fx-hint">Once recorded, the figures, period and reference can never change.</p>
                <div class="fx-formactions"><x-fx.button variant="primary" data-fx-confirm="Record this return as submitted by you? It will be frozen." data-fx-confirm-label="Record">Record the submission</x-fx.button></div>
            </form>
            @else
                <p class="fx-hint">Your role cannot record a submission.</p>
            @endif
            @if ($canPrepare)
            <form method="POST" action="{{ route('tax.returns.withdraw', $return) }}" class="fx-form fx-cluster">@csrf
                <x-fx.input name="reason" label="Withdraw the preparation" hint="Why. It goes back to a draft." /><x-fx.button>Withdraw</x-fx.button></form>
            @endif
        @elseif ($return->status === 'submitted')
            @if ($totals['payable'] > 0 && $canRecord)
            <form method="POST" action="{{ route('tax.returns.payment', $return) }}" enctype="multipart/form-data" class="fx-form fx-stack">@csrf
                <h3>Record the payment you made</h3>
                <div class="fx-row fx-row--3">
                    <x-fx.input name="amount" label="Amount paid (ZAR)" figure :value="\App\Support\Money::toDecimalString($totals['payable'])" />
                    <x-fx.input name="paid_on" label="Date paid" type="date" :value="$today->toDateString()" />
                    <x-fx.input name="reference" label="Payment reference" optional />
                </div>
                <x-fx.input name="proof" label="Proof of payment" type="file" optional />
                <p class="fx-hint">Dot.Finance does not make payments. Pay through eFiling or by EFT with the payment reference, then record it here.</p>
                <div class="fx-formactions"><x-fx.button variant="primary">Record the payment</x-fx.button></div>
            </form>
            @elseif ($totals['payable'] <= 0)
                <p>Nothing to pay on this return.</p>
            @endif
        @else
            <p>Paid {{ \App\Support\Money::format($return->paid_cents, 'ZAR') }} on {{ $return->paid_on->format('j F Y') }}@if($return->payment_reference), reference {{ $return->payment_reference }}@endif. Recorded by a person.</p>
        @endif
        @if ($return->isSubmitted() && ! $superseded && $canPrepare)
            <form method="POST" action="{{ route('tax.returns.revision', $return) }}">@csrf<x-fx.button data-fx-confirm="Start a revision? A new draft is created and this return stays as it is." data-fx-confirm-label="Start revision">Start a revision to correct it</x-fx.button></form>
        @endif
        </div>
    </x-fx.panel>

    @if ($isVat && ! $return->isDraft() && $canPrepare && $canPost)
    <x-fx.panel title="Settlement journal" meta="Suggested, not posted until you confirm">
        @if ($return->settlement_entry_id)
            <p>Posted: <a class="fx-link" href="{{ route('accounting.journals.show', $return->settlement_entry_id) }}">journal entry</a>.</p>
        @elseif (count($settlement) === 0)<p class="fx-hint">No VAT moved through the VAT accounts in this period.</p>
        @else
            <x-fx.table caption="Suggested settlement journal">
                <thead><tr><th scope="col">Account</th><th scope="col">Debit</th><th scope="col">Credit</th></tr></thead>
                <tbody>@foreach ($settlement as $l)<tr><td data-label="Account" class="is-lead">{{ str_replace('_', ' ', $l['account']) }}<span class="fx-cell-sub">{{ $l['label'] }}</span></td><td data-label="Debit" class="is-num">@if($l['debit_cents'])<x-fx.money :cents="$l['debit_cents']" currency="ZAR" />@endif</td><td data-label="Credit" class="is-num">@if($l['credit_cents'])<x-fx.money :cents="$l['credit_cents']" currency="ZAR" />@endif</td></tr>@endforeach</tbody>
            </x-fx.table>
            <form method="POST" action="{{ route('tax.returns.settlement', $return) }}">@csrf<x-fx.button data-fx-confirm="Post this journal? Journal entries are never edited afterwards, only reversed." data-fx-confirm-label="Post">Confirm and post</x-fx.button></form>
        @endif
        <x-tax.basis basis="suggestion" />
    </x-fx.panel>
    @endif

    @include('tax._rules', ['versions' => $ruleVersions])
    @if ($frozen)<p class="fx-hint">Prepared {{ $return->prepared_at->format('j M Y H:i') }}. Figures hash {{ substr($return->figures_hash, 0, 16) }}@if($integrity), intact @endif.</p>@endif
    @include('tax._footer')
</x-app-layout>
