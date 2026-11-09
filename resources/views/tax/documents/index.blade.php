<x-app-layout title="Tax documents">
    <x-fx.page-head title="Tax documents" sub="The vault: what you hold, how long to keep it, and what is missing. Records are archived, never deleted." />
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    @if (count($needs))
    <x-fx.panel title="Missing records" meta="Worked out from your books for the latest periods">
        <ul class="fx-list">@foreach ($needs as $n)<li><strong>{{ $n['label'] }}</strong> <span class="fx-hint">({{ $n['deadline']->label() }}, {{ $n['deadline']->period_label }})</span><p class="fx-hint">{{ $n['detail'] }} @if($n['href'])<a class="fx-link" href="{{ $n['href'] }}">Fix it</a>@endif <x-tax.basis :basis="$n['basis']" /></p></li>@endforeach</ul>
    </x-fx.panel>
    @endif

    <x-fx.panel title="The vault" :meta="$docs->count().' active record(s). Tax records are kept for five years.'" flush>
        <x-fx.tabs label="Category" :items="array_merge([['label' => 'All', 'href' => route('tax.documents.index'), 'current' => ! $category]], collect($categories)->map(fn ($l, $k) => ['label' => $l, 'href' => route('tax.documents.index', ['category' => $k]), 'current' => $category === $k])->values()->all())" />
        @if ($docs->isEmpty())<x-fx.empty title="Nothing filed yet.">Keep proofs of submission, SARS letters, assessments and certificates here.</x-fx.empty>@else
        <x-fx.table caption="Tax documents">
            <thead><tr><th scope="col">Document</th><th scope="col">Date</th><th scope="col">Keep until</th><th scope="col">File</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
            <tbody>
            @foreach ($docs as $d)
                <tr>
                    <td data-label="Document" class="is-lead">{{ $d->title }}<span class="fx-cell-sub">{{ $d->categoryLabel() }}@if($d->period_label), {{ $d->period_label }}@endif</span></td>
                    <td data-label="Date">{{ $d->document_date?->format('j M Y') ?? 'not dated' }}</td>
                    <td data-label="Keep until">{{ $d->retention_until->format('j M Y') }}@if($d->legal_hold) <x-fx.pill kind="variance">Legal hold</x-fx.pill>@endif<span class="fx-cell-sub">{{ \Illuminate\Support\Str::limit($d->retention_basis, 90) }}</span></td>
                    <td data-label="File">@if($d->attachment_id)<a class="fx-link" href="{{ route('tax.documents.file', $d) }}">Open</a>@else<span class="fx-hint">none attached</span>@endif</td>
                    <td data-label="" class="is-actions">
                        @if ($canManage)
                        <form method="POST" action="{{ route('tax.documents.hold', $d) }}" class="fx-cluster">@csrf<input type="hidden" name="hold" value="{{ $d->legal_hold ? 0 : 1 }}"><x-fx.button size="sm">{{ $d->legal_hold ? 'Clear hold' : 'Legal hold' }}</x-fx.button></form>
                        @if ($d->retention_until->lt($today) && ! $d->legal_hold)<form method="POST" action="{{ route('tax.documents.archive', $d) }}">@csrf<x-fx.button size="sm" data-fx-confirm="Archive this record? It and its file are kept; it only leaves the active list." data-fx-confirm-label="Archive">Archive</x-fx.button></form>@endif
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @endif
        @if ($canManage)
        <x-slot name="foot">
            <form method="POST" action="{{ route('tax.documents.store') }}" enctype="multipart/form-data" class="fx-form fx-stack">@csrf
                <div class="fx-row fx-row--3">
                    <x-fx.select name="category" label="Category">@foreach ($categories as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</x-fx.select>
                    <x-fx.input name="title" label="Title" />
                    <x-fx.input name="document_date" label="Document date" type="date" optional />
                    <x-fx.input name="period_label" label="Period" optional />
                    <x-fx.select name="tax_deadline_id" label="Belongs to a deadline" optional><option value="">None</option>@foreach ($deadlines as $dl)<option value="{{ $dl->id }}">{{ $dl->label() }}, {{ $dl->period_label }}</option>@endforeach</x-fx.select>
                    <x-fx.input name="file" label="File" type="file" optional />
                </div>
                <x-fx.check name="legal_hold">Legal hold: never archive this record</x-fx.check>
                <div class="fx-formactions"><x-fx.button>File it</x-fx.button></div>
            </form>
        </x-slot>
        @endif
    </x-fx.panel>

    <x-fx.panel title="Correspondence with SARS" flush>
        @if ($correspondence->isEmpty())<x-fx.empty title="Nothing logged.">Log letters, eFiling messages and calls, with the date a reply is due.</x-fx.empty>@else
        <x-fx.table caption="SARS correspondence log">
            <thead><tr><th scope="col">Subject</th><th scope="col">Date</th><th scope="col">Direction</th><th scope="col">Reply by</th><th scope="col">Status</th></tr></thead>
            <tbody>
            @foreach ($correspondence as $c)
                <tr>
                    <td data-label="Subject" class="is-lead">{{ $c->subject }}<span class="fx-cell-sub">{{ $c->channel }}@if($c->reference), ref {{ $c->reference }}@endif</span></td>
                    <td data-label="Date">{{ $c->occurred_on->format('j M Y') }}</td><td data-label="Direction">{{ ucfirst($c->direction) }}</td>
                    <td data-label="Reply by">{{ $c->response_due_on?->format('j M Y') ?? '' }}</td>
                    <td data-label="Status">
                        <x-fx.pill :kind="$c->status === 'open' && $c->response_due_on && $c->response_due_on->lt($today) ? 'variance' : 'plain'">{{ ucfirst($c->status) }}</x-fx.pill>
                        @if ($canManage && $c->status !== 'closed')<form method="POST" action="{{ route('tax.correspondence.status', $c) }}" class="fx-cluster">@csrf<input type="hidden" name="status" value="{{ $c->status === 'open' ? 'responded' : 'closed' }}"><x-fx.button size="sm">{{ $c->status === 'open' ? 'Mark replied' : 'Close' }}</x-fx.button></form>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @endif
        @if ($canManage)
        <x-slot name="foot">
            <form method="POST" action="{{ route('tax.correspondence.store') }}" enctype="multipart/form-data" class="fx-form fx-stack">@csrf
                <div class="fx-row fx-row--3">
                    <x-fx.select name="direction" label="Direction"><option value="received">Received from SARS</option><option value="sent">Sent to SARS</option></x-fx.select>
                    <x-fx.select name="channel" label="Channel">@foreach (['efiling' => 'eFiling', 'email' => 'E-mail', 'letter' => 'Letter', 'phone' => 'Phone', 'branch' => 'Branch', 'other' => 'Other'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</x-fx.select>
                    <x-fx.input name="occurred_on" label="Date" type="date" :value="$today->toDateString()" />
                    <x-fx.input name="subject" label="Subject" />
                    <x-fx.input name="reference" label="SARS reference" optional />
                    <x-fx.input name="response_due_on" label="Reply due by" type="date" optional />
                </div>
                <x-fx.textarea name="summary" label="Summary" optional />
                <x-fx.input name="file" label="Copy of the letter" type="file" optional />
                <div class="fx-formactions"><x-fx.button>Log it</x-fx.button></div>
            </form>
        </x-slot>
        @endif
    </x-fx.panel>
    @include('tax._footer')
</x-app-layout>
