@if ($paginator->hasPages())
<nav class="fx-pager" role="navigation" aria-label="Pagination">
    @if ($paginator->onFirstPage())
        <span class="fx-pager__page is-off" aria-disabled="true">Previous</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
    @endif
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
    @else
        <span class="fx-pager__page is-off" aria-disabled="true">Next</span>
    @endif
</nav>
@endif
