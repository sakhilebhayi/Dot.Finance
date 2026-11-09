@php
    use App\Support\Money;
    $d = $document;
    $ccy = $d->currency;
    $user = auth()->user();
    $can = fn (string $cap) => \App\Support\Ledger\LedgerAccess::allows(\App\Enums\Capability::from($cap));
    $canIssue = $can('issue-invoice');
    $canPay = $can('record-payment');
    $sealed = $d->isSealed();
    $owing = $pool['foreign'] ?? null;
    $credited = $d->isReceivable() && $sealed && $d->status === 'issued' && $owing === 0;
    $group = $d->isCredit() ? 'credit-notes' : (in_array($d->kind, ['quote', 'pro_forma']) ? 'quotes' : 'invoices');
    $eventText = [
        'issued' => 'Issued', 'emailed' => 'Handed to the mail system', 'email_failed' => 'E-mail could not be handed to the mail system', 'viewed' => 'Opened by the customer through the link',
        'link_created' => 'Customer link made', 'link_revoked' => 'Customer link switched off', 'voided' => 'Voided', 'paid' => 'Paid in full', 'payment_applied' => 'Payment applied',
        'credit_applied' => 'Credit note applied', 'credit_used' => 'Used against an invoice', 'credit_refunded' => 'Refunded to the customer', 'application_released' => 'Application taken back',
        'written_off' => 'Written off', 'write_off_requested' => 'Write-off sent for approval', 'reminder_sent' => 'Payment reminder handed to the mail system', 'converted' => 'Turned into an invoice',
        'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired',
    ];
@endphp
<x-app-layout :title="$d->title().' '.$d->displayRef()">
<x-fx.page-head :title="$d->title().' '.$d->displayRef()">
    <x-slot name="actions">
        @if ($d->status === 'draft' && $user->can('update', $d))<x-fx.button variant="secondary" icon="pencil" :href="route('sales.documents.edit', $d)">Edit</x-fx.button>@endif
        <x-fx.button variant="secondary" icon="file-text" :href="route('sales.documents.print', $d)">Print view</x-fx.button>
        <x-fx.button variant="secondary" icon="download" :href="route('sales.documents.pdf', $d)">PDF</x-fx.button>
    </x-slot>
</x-fx.page-head>
@include('sales._tabs', ['active' => $group])
<x-fx.flash />
<x-fx.errors />

<p class="sx-lead">
    <a class="fx-link" href="{{ route('sales.customers.show', $d->party_id) }}">{{ $d->party?->name }}</a>
    <x-sales.status :document="$d" :credited="$credited" />
    @if ($d->parent)<span>Corrects <a class="fx-link" href="{{ route('sales.documents.show', $d->parent) }}">{{ $d->parent->number }}</a></span>@endif
    @if ($d->source_document_id)<span>From <a class="fx-link" href="{{ route('sales.documents.show', $d->source_document_id) }}">a {{ $d->kind === 'tax_invoice' ? 'quote or pro forma' : 'document' }}</a></span>@endif
    @if ($d->converted_to_id)<span>Became <a class="fx-link" href="{{ route('sales.documents.show', $d->converted_to_id) }}">an invoice</a></span>@endif
    @if ($sealed)<span class="sx-muted">Issued {{ $d->issue_date->format('j M Y') }}@if ($d->due_date), due {{ $d->due_date->format('j M Y') }}@endif</span>@endif
</p>

@if ($d->status === 'awaiting_approval')
<div class="fx-alert" role="status"><x-fx.icon name="clock" /><div><p class="fx-alert__title">Waiting for approval</p><p>This amount needs a second person to approve it. It has no number and is not in the books yet; it issues itself when it is approved.@if ($approval) <a class="fx-link" href="{{ route('controls.approvals.index') }}">Approvals</a>@endif</p></div></div>
@endif
@if ($d->approval_note && $d->status === 'draft')<div class="fx-alert" role="status"><x-fx.icon name="circle-alert" /><p>{{ $d->approval_note }}</p></div>@endif
@if ($d->isVoid())<div class="fx-alert" role="status"><x-fx.icon name="ban" /><div><p class="fx-alert__title">Void</p><p>{{ $d->void_reason }}. The number stays on record and the posting was reversed.</p></div></div>@endif
@if ($newLink)
<div class="fx-alert fx-alert--ok" role="status"><x-fx.icon name="check" /><div><p class="fx-alert__title">Copy the link now</p><label class="sx-sr" for="new-link">Customer link</label><input id="new-link" class="fx-input" readonly value="{{ $newLink }}" size="60"><p class="sx-hint">It opens a read-only copy without signing in, and only its owner of the link can see it. It is shown once.</p></div></div>
@endif

<div class="fx-grid fx-grid--form">
<div class="fx-stack">
    <x-fx.panel title="Lines" flush>
        <x-fx.table caption="Lines on {{ $d->displayRef() }}" class="fx-table--compact">
            <thead><tr><th scope="col">Description</th><th scope="col" class="is-num">{{ $d->vat_registered ? 'Excl. VAT' : 'Amount' }}</th>@if ($d->vat_registered)<th scope="col" class="is-num">VAT</th><th scope="col" class="is-num">Total</th>@endif</tr></thead>
            <tbody>
            @foreach ($d->lines as $l)
                <tr>
                    <td data-label="Description" class="is-lead"><span class="fx-cell-main">{{ $l->description }}</span>
                        <div class="fx-cell-sub">{{ $l->quantity() }}{{ $l->unit ? ' '.$l->unit : '' }} at {{ Money::format((int) $l->unit_cents, $ccy) }}@if ($l->discount_bp || $l->discount_cents), discount {{ Money::format((int) $l->line_discount_cents, $ccy) }}@endif @if ($d->vat_registered && $l->tax_code) · {{ $l->rate_bp ? rtrim(rtrim(number_format($l->rate_bp / 100, 2, '.', ''), '0'), '.').'% VAT' : str_replace('_', ' ', strtolower(str_replace('ZA_VAT_', '', $l->tax_code))) }}@endif</div></td>
                    <td data-label="{{ $d->vat_registered ? 'Excl. VAT' : 'Amount' }}" class="is-num"><x-fx.money :cents="$l->net_cents" :currency="$ccy" /></td>
                    @if ($d->vat_registered)
                    <td data-label="VAT" class="is-num"><x-fx.money :cents="$l->tax_cents" :currency="$ccy" /></td>
                    <td data-label="Total" class="is-num"><x-fx.money :cents="$l->gross_cents" :currency="$ccy" /></td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        <div class="sx-totalsbox">
            <x-fx.statement>
                @if ($d->vat_registered)<x-fx.statement-row label="Total before VAT"><x-fx.money :cents="$d->net_cents" :currency="$ccy" /></x-fx.statement-row><x-fx.statement-row label="VAT"><x-fx.money :cents="$d->tax_cents" :currency="$ccy" /></x-fx.statement-row>@endif
                <x-fx.statement-row label="Total" kind="total"><x-fx.money :cents="$d->total_cents" :currency="$ccy" :trace="$d->gl_entry_id ? route('accounting.journals.show', $d->gl_entry_id) : null" trace-label="Show the journal entry this was posted as" /></x-fx.statement-row>
                @if ($d->isForeign() && $d->postsToBooks())<x-fx.statement-row label="In {{ $d->base_currency }} at {{ \App\Services\Shared\FxMath::formatRate((int) $d->fx_rate_e8) }} ({{ $d->fx_rate_source }}, {{ $d->fx_rate_as_of?->format('j M Y') }})"><x-fx.money :cents="$d->base_total_cents" :currency="$d->base_currency" /></x-fx.statement-row>@endif
            </x-fx.statement>
            @if ($d->vat_registered)<p class="sx-hint">VAT {{ $d->tax_rounding_level === 'document' ? 'rounded on the whole document' : 'rounded line by line' }}. Rule versions {{ implode(', ', $d->tax_rule_versions ?? []) }}. Confidence: {{ $d->tax_confidence }}. Calculated.</p>@endif
        </div>
    </x-fx.panel>

    @if ($d->notes || $d->terms || $d->reason || $d->internal_note)
    <x-fx.panel title="Notes">
        @if ($d->reason)<p><strong>Reason.</strong> {{ $d->reason }}</p>@endif
        @if ($d->notes)<p class="sx-pre">{{ $d->notes }}</p>@endif
        @if ($d->terms)<p class="sx-pre sx-muted">{{ $d->terms }}</p>@endif
        @if ($d->internal_note)<p class="sx-pre sx-muted"><strong>Internal.</strong> {{ $d->internal_note }}</p>@endif
    </x-fx.panel>
    @endif

    @if ($d->isReceivable() && $sealed)
    <x-fx.panel title="Payments and credits applied" flush>
        @if ($applied->isNotEmpty())
        <x-fx.table caption="What has been applied to {{ $d->number }}" class="fx-table--compact">
            <thead><tr><th scope="col">Date</th><th scope="col">What</th><th scope="col" class="is-num">Amount</th><th scope="col">State</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
            <tbody>@foreach ($applied as $a)
                <tr>
                    <td data-label="Date">{{ $a->applied_on->format('j M Y') }}</td>
                    <td data-label="What" class="is-lead">@if ($a->kind === 'payment')<a class="fx-link" href="{{ route('sales.payments.show', $a->payment_id) }}">Payment {{ $a->payment?->reference ?: '#'.$a->payment_id }}</a>@else<a class="fx-link" href="{{ route('sales.documents.show', $a->source_document_id) }}">Credit note {{ $a->source?->number }}</a>@endif
                        @if ($a->fx_diff_base_cents)<div class="fx-cell-sub">Exchange {{ $a->fx_diff_base_cents > 0 ? 'gain' : 'loss' }} {{ Money::format(abs($a->fx_diff_base_cents), $d->base_currency) }} realised</div>@endif</td>
                    <td data-label="Amount" class="is-num"><x-fx.money :cents="$a->amount_cents" :currency="$ccy" /></td>
                    <td data-label="State">{!! $a->released_at ? '<span class="sx-muted">Taken back '.e($a->released_at->format('j M Y')).'</span>' : 'Applied' !!}</td>
                    <td class="is-actions">@if ($a->isLive() && $canPay)<details class="sx-inline"><summary class="fx-link">Take back</summary><form method="POST" action="{{ route('sales.applications.release', $a) }}" class="fx-cluster">@csrf<label class="sx-sr" for="rel-{{ $a->id }}">Reason</label><input id="rel-{{ $a->id }}" class="fx-input" name="reason" placeholder="Why" required maxlength="255"><x-fx.button size="sm">Take back</x-fx.button></form></details>@endif</td>
                </tr>@endforeach</tbody>
        </x-fx.table>
        @else
        <x-fx.empty>Nothing has been paid or credited against this yet.</x-fx.empty>
        @endif
    </x-fx.panel>
    @endif

    @if ($d->isCredit() && $sealed)
    <x-fx.panel title="Where this credit went" flush>
        @if ($used->isNotEmpty())
        <x-fx.table caption="Where credit note {{ $d->number }} was applied" class="fx-table--compact"><thead><tr><th scope="col">Date</th><th scope="col">Invoice</th><th scope="col" class="is-num">Amount</th><th scope="col">State</th></tr></thead>
        <tbody>@foreach ($used as $u)<tr><td data-label="Date">{{ $u->applied_on->format('j M Y') }}</td><td data-label="Invoice" class="is-lead"><a class="fx-link" href="{{ route('sales.documents.show', $u->target_document_id) }}">{{ $u->target?->number }}</a></td><td data-label="Amount" class="is-num"><x-fx.money :cents="$u->amount_cents" :currency="$ccy" /></td><td data-label="State">{{ $u->released_at ? 'Taken back' : 'Applied' }}</td></tr>@endforeach</tbody></x-fx.table>
        @else<x-fx.empty>Not applied to an invoice yet.</x-fx.empty>@endif
    </x-fx.panel>
    @endif

    <x-fx.panel title="History" flush>
        @if ($d->events->isNotEmpty())
        <ol class="sx-trail">@foreach ($d->events->reverse() as $e)<li><span class="sx-trail__when">{{ $e->created_at->format('j M Y, H:i') }}</span><span>{{ $eventText[$e->kind] ?? ucfirst(str_replace('_', ' ', $e->kind)) }}@if (! empty($e->meta['to'])) to {{ $e->meta['to'] }}@endif @if (! empty($e->meta['reason'])): {{ $e->meta['reason'] }}@endif @if (! empty($e->meta['number']) && $e->kind === 'issued') as {{ $e->meta['number'] }}@endif <span class="sx-muted">{{ $e->actor?->name ? 'by '.$e->actor->name : '' }}</span></span></li>@endforeach</ol>
        @else<x-fx.empty>History starts when the document is issued. A draft has no trail.</x-fx.empty>@endif
    </x-fx.panel>
</div>

<aside class="fx-stack">
    @if ($d->isReceivable() && $sealed && ! $d->isVoid())
    <x-fx.panel title="What is owed">
        <x-fx.statement>
            <x-fx.statement-row label="{{ $d->title() }} total"><x-fx.money :cents="$d->total_cents" :currency="$ccy" /></x-fx.statement-row>
            @php $credits = (int) $applied->where('kind', 'credit')->whereNull('released_at')->sum('amount_cents'); $paid = (int) $applied->where('kind', 'payment')->whereNull('released_at')->sum('amount_cents'); @endphp
            @if ($credits)<x-fx.statement-row label="Credit notes applied"><x-fx.money :cents="-$credits" :currency="$ccy" /></x-fx.statement-row>@endif
            @if ($paid)<x-fx.statement-row label="Paid"><x-fx.money :cents="-$paid" :currency="$ccy" /></x-fx.statement-row>@endif
            @if ($d->written_off_cents)<x-fx.statement-row label="Written off"><x-fx.money :cents="-$d->written_off_cents" :currency="$ccy" /></x-fx.statement-row>@endif
            <x-fx.statement-row label="Owing" kind="total"><x-fx.money :cents="$owing" :currency="$ccy" :variance="$owing > 0 && $d->isOverdue()" /></x-fx.statement-row>
        </x-fx.statement>
        @if ($d->isOverdue())<p class="sx-hint sx-hint--variance">{{ (int) $d->due_date->diffInDays(now()) }} days past the due date.</p>@endif
        @if ($owing > 0 && $canPay)<div class="sx-cta"><x-fx.button variant="primary" icon="hand-coins" :href="route('sales.payments.create', ['document' => $d->id])">Record a payment</x-fx.button></div>@endif
    </x-fx.panel>
    @endif

    @if ($creditPool)
    <x-fx.panel title="Customer credit left"><x-fx.statement><x-fx.statement-row label="Not yet applied or refunded" kind="total"><x-fx.money :cents="$creditPool['foreign']" :currency="$ccy" /></x-fx.statement-row></x-fx.statement></x-fx.panel>
    @endif

    <x-fx.panel title="{{ $check['applies'] ? ($sealed ? 'Valid tax invoice' : 'Is it a valid tax invoice?') : 'Checks' }}" meta="{{ $check['applies'] ? 'From the VAT Act requirements' : '' }}">
        <ul class="sx-checklist">
        @foreach ($check['items'] as $item)
            <li class="{{ $item['ok'] ? 'is-ok' : ($item['blocking'] ? 'is-bad' : 'is-note') }}"><x-fx.icon :name="$item['ok'] ? 'check' : 'circle-alert'" /><span><strong>{{ $item['label'] }}</strong> <span class="sx-muted">{{ $item['detail'] }}</span></span></li>
        @endforeach
        </ul>
        @if ($check['applies'])<p class="sx-hint">{{ $check['valid'] ? 'Every required item is there.' : 'Fix the items marked before this can be issued.' }} This lists what is on the document; it is not tax advice.</p>@endif
    </x-fx.panel>

    <x-fx.panel title="Actions">
        <div class="sx-acts">
        @if ($d->status === 'draft' && $user->can('issue', $d))
            <form method="POST" action="{{ route('sales.documents.issue', $d) }}" class="fx-form">@csrf
                @if ($errors->has('sales') && str_contains($errors->first('sales'), 'credit limit'))<x-fx.check name="acknowledge_credit_limit" id="ack-limit">I know, issue it anyway</x-fx.check>@endif
                <x-fx.button variant="primary" icon="check">Issue {{ strtolower($d->title()) }}</x-fx.button>
                <p class="sx-hint">Gives it the next number{{ $d->postsToBooks() ? ' and posts it to the books' : '' }}. After that it cannot be edited: it can only be voided or credited.</p>
            </form>
        @endif
        @if ($d->isReceivable() && $sealed && ! $d->isVoid() && $user->can('correct', $d))
            <details class="sx-act"><summary>Issue a credit note</summary>
                <form method="POST" action="{{ route('sales.documents.credit', $d) }}" class="fx-form">@csrf
                    <x-fx.input name="reason" id="cn-reason" label="Reason" optional maxlength="255" hint="Needed to issue it. You can add it in the draft." />
                    <p class="sx-hint">Makes a draft crediting what is left on every line ({{ $remainingCredit ? Money::format($remainingCredit['gross'], $ccy) : '' }} gross). Edit it to credit less.</p>
                    <x-fx.button>Make the draft</x-fx.button></form></details>
            @if ($d->kind === 'tax_invoice')<details class="sx-act"><summary>Issue a debit note</summary>
                <form method="POST" action="{{ route('sales.documents.debit', $d) }}" class="fx-form">@csrf
                    <x-fx.input name="reason" id="dn-reason" label="Reason" optional maxlength="255" /><x-fx.button>Make the draft</x-fx.button></form></details>@endif
        @endif
        @if ($sealed && ! $d->isVoid() && $user->can('send', $d))
            <details class="sx-act"><summary>E-mail it</summary>
                <form method="POST" action="{{ route('sales.documents.send', $d) }}" class="fx-form">@csrf
                    <x-fx.input name="to" id="mail-to" type="email" label="To" :value="$d->party?->email" required />
                    <x-fx.textarea name="message" id="mail-msg" label="Message" optional rows="3" />
                    <x-fx.check name="include_link" id="mail-link" :checked="true">Include a link to view it online</x-fx.check>
                    <x-fx.button icon="send">Send</x-fx.button>
                    <p class="sx-hint">The PDF is attached. The system can only tell you the message was handed to the mail system, not that it arrived.</p></form></details>
            <details class="sx-act"><summary>Make a customer link</summary>
                <form method="POST" action="{{ route('sales.documents.share', $d) }}" class="fx-form">@csrf
                    <x-fx.input name="days" id="link-days" label="Works for (days)" type="number" :value="30" min="1" max="180" /><x-fx.button>Make link</x-fx.button>
                    <p class="sx-hint">A read-only copy, no sign-in. You can switch it off any time.</p></form></details>
        @endif
        @if (in_array($d->kind, ['quote', 'pro_forma']) && $sealed && ! $d->isVoid() && $user->can('correct', $d))
            @unless ($d->converted_to_id)<form method="POST" action="{{ route('sales.documents.convert', $d) }}">@csrf<x-fx.button variant="primary" icon="arrow-right">Turn into an invoice</x-fx.button></form>@endunless
            @if ($d->kind === 'quote' && $d->status === 'issued')
            <form method="POST" action="{{ route('sales.documents.answer', $d) }}" class="fx-cluster">@csrf<input type="hidden" name="answer" value="accepted"><x-fx.button>Customer accepted</x-fx.button></form>
            <form method="POST" action="{{ route('sales.documents.answer', $d) }}" class="fx-cluster">@csrf<input type="hidden" name="answer" value="declined"><x-fx.button>Customer declined</x-fx.button></form>
            @endif
        @endif
        @if ($d->isReceivable() && $sealed && ! $d->isVoid() && $owing > 0 && $user->can('writeOff', $d))
            <details class="sx-act"><summary>Write off a debt</summary>
                <form method="POST" action="{{ route('sales.documents.write-off', $d) }}" class="fx-form">@csrf
                    <x-fx.input name="amount" id="wo-amount" label="Amount" figure inputmode="decimal" :value="Money::toDecimalString($owing)" required />
                    <x-fx.input name="reason" id="wo-reason" label="Reason" maxlength="255" required />
                    <p class="sx-hint">Posts to bad debts and marks the invoice paid if it clears the balance. Needs someone who can approve invoices, or an approval rule. VAT relief is not claimed here.</p>
                    <x-fx.button variant="danger">Write off</x-fx.button></form></details>
        @endif
        @if ($sealed && ! $d->isVoid() && $user->can('void', $d))
            <details class="sx-act"><summary>Void this {{ strtolower($d->title()) }}</summary>
                <form method="POST" action="{{ route('sales.documents.void', $d) }}" class="fx-form" data-fx-confirm="Void {{ $d->number }}? The number stays on record and the posting is reversed." data-fx-confirm-label="Void">@csrf
                    <x-fx.input name="reason" id="void-reason" label="Reason" maxlength="255" required />
                    <p class="sx-hint">Only possible while nothing is paid or credited against it. A period that is closed needs a credit note instead.</p>
                    <x-fx.button variant="danger">Void</x-fx.button></form></details>
        @endif
        @if (in_array($d->status, ['draft', 'awaiting_approval']) && $user->can('discard', $d))
            <form method="POST" action="{{ route('sales.documents.discard', $d) }}" data-fx-confirm="Discard this draft? It has no number, so nothing is missing from the sequence." data-fx-confirm-label="Discard">@csrf @method('DELETE')<x-fx.button variant="danger" size="sm">Discard draft</x-fx.button></form>
        @endif
        </div>
    </x-fx.panel>

    @if ($unapplied->isNotEmpty() && $canPay && $owing > 0)
    <x-fx.panel title="Credit {{ $d->party?->name }} already holds">
        <ul class="sx-credits">@foreach ($unapplied as $c)
            <li><span>{{ $c['label'] }}</span><x-fx.money :cents="$c['cents']" :currency="$c['currency']" />
                <form method="POST" action="{{ $c['kind'] === 'payment' ? route('sales.payments.allocate', $c['id']) : route('sales.documents.apply-credit', $d) }}" class="fx-cluster">@csrf
                    <input type="hidden" name="{{ $c['kind'] === 'payment' ? 'document_id' : 'credit_id' }}" value="{{ $c['kind'] === 'payment' ? $d->id : $c['id'] }}">
                    <label class="sx-sr" for="use-{{ $c['kind'] }}-{{ $c['id'] }}">Amount to apply</label><input id="use-{{ $c['kind'] }}-{{ $c['id'] }}" class="fx-input fx-input--figure" name="amount" value="{{ Money::toDecimalString(min($c['cents'], $owing)) }}" inputmode="decimal" size="10"><x-fx.button size="sm">Apply</x-fx.button></form></li>
        @endforeach</ul>
    </x-fx.panel>
    @endif

    @if ($links->isNotEmpty())
    <x-fx.panel title="Customer links">
        <ul class="sx-links">@foreach ($links as $l)<li><span>Made {{ $l->created_at->format('j M') }} · {{ $l->views }} view{{ $l->views == 1 ? '' : 's' }}<br><span class="sx-muted">{{ $l->revoked_at ? 'Switched off' : ($l->expires_at->isPast() ? 'Expired' : 'Works until '.$l->expires_at->format('j M Y')) }}</span></span>
            @if ($l->isActive() && $user->can('send', $d))<form method="POST" action="{{ route('sales.links.revoke', $l) }}">@csrf<button class="fx-linkbtn fx-linkbtn--danger">Switch off</button></form>@endif</li>@endforeach</ul>
    </x-fx.panel>
    @endif
</aside>
</div>
</x-app-layout>
