@props(['name' => 'file', 'id', 'accept' => null, 'required' => false, 'describedby' => null, 'title' => 'Drop a file here', 'sub' => 'or choose one from your device'])
{{-- A file field with a drop target. It is still the browser's own file input (keyboard, screen readers, drag and drop all work);
     the box around it only makes it large and says what it takes. The chosen file's name is written in by forms.js. --}}
<div class="fx-drop" data-fx-drop>
    <input id="{{ $id }}" class="fx-drop__input" type="file" name="{{ $name }}" @if ($accept) accept="{{ $accept }}" @endif @required($required) @if ($describedby) aria-describedby="{{ $describedby }}" @endif>
    <div class="fx-drop__body" aria-hidden="true">
        <x-fx.icon name="upload" />
        <strong class="fx-drop__title">{{ $title }}</strong>
        <span class="fx-drop__sub">{{ $sub }}</span>
        <span class="fx-drop__name" data-fx-drop-name></span>
    </div>
</div>
