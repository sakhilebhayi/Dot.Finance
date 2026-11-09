@if ($errors->any())
<div {{ $attributes->class(['fx-alert']) }} role="alert">
    <x-fx.icon name="circle-alert" />
    <div>
        <p class="fx-alert__title">Please check the following</p>
        <ul>
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
</div>
@endif
