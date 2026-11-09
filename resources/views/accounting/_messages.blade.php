@if (session('status'))
    <p class="acct-status" role="status">{{ session('status') }}</p>
@endif
@if ($errors->any())
    <div class="acct-errors" role="alert">
        <ul>
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
