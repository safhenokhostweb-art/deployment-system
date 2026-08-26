@props(['level' => 'info', 'title' => null])
@php($icons = ['success' => '✓', 'warning' => '!', 'danger' => '×', 'info' => 'i', 'neutral' => '•'])
<div {{ $attributes->class(['ui-alert', 'ui-alert--'.$level]) }} role="{{ in_array($level, ['danger', 'warning'], true) ? 'alert' : 'status' }}">
    <span class="ui-alert__icon" aria-hidden="true">{{ $icons[$level] ?? $icons['neutral'] }}</span>
    <div class="ui-alert__body">
        @if ($title)<strong class="ui-alert__title">{{ $title }}</strong>@endif
        {{ $slot }}
    </div>
</div>
