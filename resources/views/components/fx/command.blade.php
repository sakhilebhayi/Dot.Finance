@props(['nav' => [], 'quick' => []])
{{-- The command bar. Rendered by the server from what exists and what this person may use; the script only filters it. --}}
<dialog id="fx-command" class="fx-dialog fx-command" aria-label="Quick actions and search">
    <div class="fx-command__field">
        <x-fx.icon name="search" />
        <input class="fx-command__input" type="search" data-command-input placeholder="Type to find a page or an action" aria-label="Find a page or an action" autocomplete="off" />
    </div>
    <ul class="fx-command__list" role="list">
        @if (count($quick))
        <li data-command-group>
            <p class="fx-command__group">Quick actions</p>
            <ul role="list">
                @foreach ($quick as $action)
                <li class="fx-command__opt"><a href="{{ $action['url'] }}" data-command-option data-label="{{ $action['label'] }}"><x-fx.icon :name="$action['icon']" />{{ $action['label'] }}</a></li>
                @endforeach
            </ul>
        </li>
        @endif
        @foreach ($nav as $group)
        @php $available = array_filter($group['items'], fn ($i) => $i['available']); @endphp
        @if (count($available))
        <li data-command-group>
            <p class="fx-command__group">{{ $group['label'] }}</p>
            <ul role="list">
                @foreach ($available as $item)
                <li class="fx-command__opt"><a href="{{ $item['url'] }}" data-command-option data-label="{{ $group['label'] }} {{ $item['label'] }}"><x-fx.icon :name="$item['icon']" />{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
        </li>
        @endif
        @endforeach
        @if (fx_route_exists('transactions.index'))
        <li class="fx-command__opt" hidden><a href="#" data-command-search data-base="{{ parse_url(route('transactions.index'), PHP_URL_PATH) }}"><x-fx.icon name="search" />Search transactions for &ldquo;<span data-command-search-term></span>&rdquo;</a></li>
        @endif
    </ul>
    <p class="fx-command__empty" data-command-empty hidden>Nothing matches. Try a different word.</p>
</dialog>
