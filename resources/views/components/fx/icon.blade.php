@props(['name', 'label' => null])
<svg {{ $attributes->class(['fx-icon']) }} viewBox="0 0 24 24" aria-hidden="{{ $label ? 'false' : 'true' }}" @if ($label) role="img" aria-label="{{ $label }}" @endif focusable="false">{!! \App\Support\Ui\Icons::inner($name) !!}</svg>
