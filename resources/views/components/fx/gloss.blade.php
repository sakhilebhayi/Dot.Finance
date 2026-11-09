@props(['term' => null, 'glossary' => null])
@php
    // The explanation comes from, in order: the `glossary` slot, the term looked up in App\Support\Glossary (when the
    // shared glossary exists), or nothing, in which case the term is shown plainly with no tooltip.
    $text = isset($glossary) ? trim((string) $glossary) : null;
    if (($text === null || $text === '') && $term && class_exists(\App\Support\Glossary::class)) {
        foreach (['explain', 'for', 'get', 'ownerExplanation'] as $method) {
            if (method_exists(\App\Support\Glossary::class, $method)) {
                $found = \App\Support\Glossary::$method($term);
                $text = is_array($found) ? ($found['owner_explanation'] ?? $found['explanation'] ?? null) : (is_string($found) ? $found : null);
                break;
            }
        }
    }
    $tipId = 'gloss-'.substr(md5(($term ?? '').($text ?? '').uniqid('', true)), 0, 8);
@endphp
@if ($text)
<span class="fx-gloss" tabindex="0" aria-describedby="{{ $tipId }}">{{ $slot->isNotEmpty() ? $slot : $term }}<span class="fx-gloss__tip" role="tooltip" id="{{ $tipId }}">{{ $text }}</span></span>
@else
{{ $slot->isNotEmpty() ? $slot : $term }}
@endif
