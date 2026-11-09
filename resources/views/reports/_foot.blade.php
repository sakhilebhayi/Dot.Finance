@if (count($result->header['assumptions'] ?? []))
<section aria-labelledby="rp-assumptions">
    <h2 class="fx-section-title" id="rp-assumptions">Assumptions and method</h2>
    <ul class="rp-assumptions">
        @foreach ($result->header['assumptions'] as $a)<li>{{ $a }}</li>@endforeach
    </ul>
</section>
@endif
@if (count($result->notes))
<ul class="rp-notes">
    @foreach ($result->notes as $note)<li>{{ $note }}</li>@endforeach
</ul>
@endif
