@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'fx-alert']) }} role="alert">
        <x-fx.icon name="circle-alert" />
        <div>
            <p class="fx-alert__title">{{ __('Please check the following') }}</p>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
