@props(['basis'])
@php $label = \App\Services\Tax\TaxBasis::label($basis); @endphp
<x-fx.pill kind="neutral" :icon="['calculated' => 'check', 'estimate' => 'chart-line', 'entered' => 'pencil', 'suggestion' => 'circle-alert'][$basis] ?? null" title="{{ \App\Services\Tax\TaxBasis::meaning($basis) }}">{{ $label }}</x-fx.pill>
