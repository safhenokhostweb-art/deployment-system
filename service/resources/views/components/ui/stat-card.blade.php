@props(['label', 'value', 'context' => null, 'level' => 'neutral'])
<div {{ $attributes->class(['ui-card', 'ui-stat-card', 'ui-stat-card--'.$level]) }}>
    <span class="ui-stat-card__label">{{ $label }}</span>
    <strong class="ui-stat-card__value">{{ $value }}</strong>
    @if ($context)<span class="ui-stat-card__context">{{ $context }}</span>@endif
    {{ $slot }}
</div>
