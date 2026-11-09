@props(['name', 'large' => false])
{{-- Initials, drawn locally. Jetstream's default avatar is a request to a third-party service with the person's name in the URL; this replaces it. --}}
<span {{ $attributes->class(['fx-avatar', 'fx-avatar--lg' => $large]) }} aria-hidden="true">{{ strtoupper(mb_substr(trim((string) $name), 0, 1)) }}</span>
