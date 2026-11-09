<x-fx.panel :title="$title ?? 'Documents'" :meta="$meta ?? 'The original file is kept exactly as uploaded.'">
    @if (collect($files)->isEmpty())
        <x-fx.empty title="No document attached.">Attach the supplier's invoice or credit note: it is the evidence for what is on the books.</x-fx.empty>
    @else
    <ul class="fx-list">
        @foreach ($files as $f)
        <li><a class="fx-link" href="{{ $f['url'] }}" target="_blank" rel="noopener">{{ $f['file']->original_name }}</a> <span class="fx-cell-sub">{{ number_format($f['file']->size / 1024, 0) }} KB, {{ $f['file']->created_at->format('j M Y') }} · <a class="fx-link" href="{{ $f['download'] }}">Download</a></span></li>
        @endforeach
    </ul>
    @endif
    @if ($canAttach ?? true)
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="fx-form">
        @csrf
        <x-fx.field label="Attach a file" for="{{ $inputId ?? 'file' }}" :error="$errors->first('file')">
            <input id="{{ $inputId ?? 'file' }}" type="file" name="file" class="fx-input" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.txt,.csv">
        </x-fx.field>
        <div class="fx-formactions"><x-fx.button icon="upload">Attach</x-fx.button></div>
    </form>
    @endif
</x-fx.panel>
