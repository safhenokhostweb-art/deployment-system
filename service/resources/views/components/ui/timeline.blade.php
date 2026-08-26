@props(['label' => null])
<ol {{ $attributes->class(['ui-timeline']) }} @if($label) aria-label="{{ $label }}" @endif>
    {{ $slot }}
</ol>
