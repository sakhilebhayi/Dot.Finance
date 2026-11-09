@php
    // overdue is a state worth shouting about; everything else is a plain word. Paid is the only "ticked" state, because it is the only one a payment proves.
    $label = ['draft' => 'Draft', 'awaiting_approval' => 'Awaiting approval', 'approved' => 'Approved, not posted', 'open' => 'Open', 'partially_paid' => 'Partly paid', 'paid' => 'Paid', 'void' => 'Void'][$status] ?? ucfirst($status);
    if (! empty($credit) && $status === 'paid') { $label = 'Applied'; }
    $kind = ! empty($overdue) ? 'variance' : ($status === 'paid' ? 'ticked' : 'neutral');
@endphp
<x-fx.pill :kind="$kind">{{ ! empty($overdue) ? 'Overdue' : $label }}</x-fx.pill>
