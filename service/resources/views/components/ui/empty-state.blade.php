@props(['title', 'description' => null, 'icon' => '◇'])
<div {{ $attributes->class(['ui-empty']) }} role="status">
    <span class="ui-empty__icon" aria-hidden="true">{{ $icon }}</span>
    <strong class="ui-empty__title">{{ $title }}</strong>
    @if ($description)<p class="ui-empty__description">{{ $description }}</p>@endif
    {{ $slot }}
    @isset($actions)<div class="ui-empty__actions">{{ $actions }}</div>@endisset
</div>
