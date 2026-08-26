@props(['status', 'label' => null, 'detail' => null])
@php
    $definition = \App\Support\Ui\StatusRegistry::resolve('health', $status);
    $translated = __($definition['translation_key']);
    $displayLabel = $label ?? ($translated === $definition['translation_key'] ? $definition['label'] : $translated);
@endphp
<span {{ $attributes->class(['ui-health', 'ui-health--'.$definition['level']]) }} data-health-status="{{ $status }}" role="status">
    <span class="ui-health__dot" aria-hidden="true"></span>
    <span>
        <span class="ui-health__label">{{ $displayLabel }}</span>
        @if ($detail)<span class="ui-health__detail">{{ $detail }}</span>@endif
    </span>
</span>
