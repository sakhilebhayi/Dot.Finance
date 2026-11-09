<x-app-layout title="Dot.Emall sales">

<x-fx.page-head title="Dot.Emall sales" sub="Sale lines Dot.Emall has sent for this business. Nothing here is in the books until someone with the journal capability posts it. Amounts were recomputed on arrival; a line with a problem cannot be posted.">
    <x-slot name="actions"><x-fx.button :href="route('intel.integrations.index')">All integrations</x-fx.button></x-slot>
</x-fx.page-head>

<x-fx.flash />
<x-fx.errors />

<nav class="fx-cluster" aria-label="Filter by status">
    <a href="{{ route('intel.integrations.emall.index') }}" @if (! $status) aria-current="page" @endif>All</a>
    @foreach (['staged' => 'Ready to review', 'needs_attention' => 'Needs attention', 'posted' => 'Posted', 'rejected' => 'Rejected'] as $key => $label)
    <a href="{{ route('intel.integrations.emall.index', ['status' => $key]) }}" @if ($status === $key) aria-current="page" @endif>{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
    @endforeach
</nav>

<x-fx.panel flush>
    @if ($lines->isEmpty())
        <x-fx.empty title="No Dot.Emall sales have arrived.">Dot.Emall does not send seller sales yet: the signed endpoint is ready and waiting. See the integrations page for what is needed on the Emall side.</x-fx.empty>
    @else
    <x-fx.table caption="Dot.Emall sale lines" class="intel-wrap">
        <thead><tr><th scope="col">Sold</th><th scope="col">Order</th><th scope="col">Sale</th><th scope="col" class="is-num">Gross</th><th scope="col" class="is-num">VAT</th><th scope="col" class="is-num">Fee</th><th scope="col">Status</th><th scope="col" class="is-actions">Review</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
        <tr>
            <td data-label="Sold">{{ $line->sold_on->toDateString() }}</td>
            <td data-label="Order">{{ $line->order_ref }}</td>
            <th scope="row" class="is-lead" data-label="Sale">{{ $line->description }}</th>
            <td data-label="Gross" class="is-num">{{ \App\Support\Money::format($line->gross_cents, $line->currency) }}</td>
            <td data-label="VAT" class="is-num">{{ \App\Support\Money::format($line->vat_cents, $line->currency) }}</td>
            <td data-label="Fee" class="is-num">{{ \App\Support\Money::format($line->fee_cents, $line->currency) }}</td>
            <td data-label="Status">
                <x-fx.pill :kind="$line->status === 'needs_attention' ? 'variance' : ($line->status === 'posted' ? 'ticked' : 'plain')">{{ str_replace('_', ' ', ucfirst($line->status)) }}</x-fx.pill>
                @foreach ((array) $line->problems as $problem)<p class="fx-hint">{{ $problem }}</p>@endforeach
            </td>
            <td data-label="Review" class="is-actions">
                @if ($line->status === 'staged')
                @can('post-journal')
                <form method="POST" action="{{ route('intel.integrations.emall.post', $line) }}" class="fx-cluster">
                    @csrf
                    @if ($line->fee_cents > 0)
                    <label class="sr-only" for="fee-{{ $line->id }}">Fee account</label>
                    <select id="fee-{{ $line->id }}" name="fee_account_id" class="fx-select">
                        <option value="">Cost of sales (default)</option>
                        @foreach ($expenseAccounts as $account)<option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>@endforeach
                    </select>
                    @endif
                    <x-fx.button size="sm" variant="primary">Post</x-fx.button>
                </form>
                @endcan
                @endif
                @if (in_array($line->status, ['staged', 'needs_attention'], true))
                @can('post-journal')
                <form method="POST" action="{{ route('intel.integrations.emall.reject', $line) }}">@csrf<x-fx.button size="sm" variant="ghost">Reject</x-fx.button></form>
                @endcan
                @endif
                @if ($line->journal_entry_id && fx_route_exists('accounting.journals.show'))<a href="{{ route('accounting.journals.show', $line->journal_entry_id) }}">Entry</a>@endif
            </td>
        </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    <x-slot name="foot">{{ $lines->links() }}</x-slot>
    @endif
</x-fx.panel>

</x-app-layout>
