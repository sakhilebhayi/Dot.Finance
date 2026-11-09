<div class="fx-section-title">
    <h3>{{ $title }}</h3>
    <p>{{ $description }}</p>
    @if (isset($aside) && $aside->isNotEmpty())<div>{{ $aside }}</div>@endif
</div>
