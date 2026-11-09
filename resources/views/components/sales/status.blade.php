@props(['document' => null, 'status' => null, 'kind' => null, 'credited' => false])
@php
    $kind ??= $document?->kind;
    $status ??= $document?->displayStatus();
    if ($credited && $status === 'issued') { $status = 'credited'; }
    $isQuote = in_array($kind, ['quote', 'pro_forma'], true);
    $label = match (true) {
        $status === 'issued' && $isQuote => 'Sent',
        $status === 'issued' && $kind === 'credit_note' => 'Issued',
        $status === 'awaiting_approval' => 'Awaiting approval',
        $status === 'partially_paid' => 'Part paid',
        default => ucfirst(str_replace('_', ' ', (string) $status)),
    };
    $pill = match ($status) { 'paid', 'accepted' => 'ticked', 'overdue', 'void', 'declined', 'expired' => 'variance', default => 'neutral' };
    $icon = match ($status) { 'paid', 'accepted' => 'check', 'overdue' => 'circle-alert', 'void', 'declined', 'expired' => 'ban', 'awaiting_approval' => 'clock', 'draft' => 'pencil', default => null };
@endphp
<x-fx.pill :kind="$status === 'void' ? 'plain' : $pill" :icon="$icon" {{ $attributes }}>{{ $label }}</x-fx.pill>
