@auth
@if (\Illuminate\Support\Facades\Route::has('intel.notifications.index'))
<a href="{{ route('intel.notifications.index') }}" class="fx-iconbtn" aria-label="Notifications{{ ($intelUnread ?? 0) > 0 ? ', '.$intelUnread.' unread' : '' }}" title="Notifications{{ ($intelUnread ?? 0) > 0 ? ': '.$intelUnread.' unread' : '' }}">
    <x-fx.icon name="bell" />
    @if (($intelUnread ?? 0) > 0)<span class="intel-badge" aria-hidden="true">{{ $intelUnread > 9 ? '9+' : $intelUnread }}</span>@endif
</a>
@endif
@endauth
