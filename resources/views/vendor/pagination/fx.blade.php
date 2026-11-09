@if ($paginator->hasPages())
<nav class="fx-pager" role="navigation" aria-label="Pagination">
    <p>Showing <span class="fx-fig">{{ $paginator->firstItem() }}-{{ $paginator->lastItem() }}</span> of <span class="fx-fig">{{ $paginator->total() }}</span></p>
    <div class="fx-pager__pages">
        @if ($paginator->onFirstPage())
            <span class="fx-pager__page is-off" aria-disabled="true">Previous</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="fx-pager__page is-gap">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="fx-pager__page is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
        @else
            <span class="fx-pager__page is-off" aria-disabled="true">Next</span>
        @endif
    </div>
</nav>
@endif
