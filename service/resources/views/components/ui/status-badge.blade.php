@props(['domain', 'status', 'label' => null, 'size' => 'normal'])
@php
    $definition = \App\Support\Ui\StatusRegistry::resolve($domain, $status);
    $translated = __($definition['translation_key']);
    $displayLabel = $label ?? ($translated === $definition['translation_key'] ? $definition['label'] : $translated);
    $icons = [
        'check' => '✓', 'verified' => '✓', 'connected' => '●', 'package' => '◆',
        'clock' => '◷', 'progress' => '↻', 'build' => '◇', 'transfer' => '⇄',
        'upload' => '↑', 'activate' => '↥', 'verify' => '◎', 'rollback' => '↶',
        'update' => '↑', 'health' => '!', 'error' => '×', 'missing' => '−',
        'disconnected' => '○', 'neutral' => '•',
    ];
@endphp
<span
    {{ $attributes->class(['ui-status', 'ui-status--'.$definition['level'], 'ui-status--small' => $size === 'small']) }}
    data-domain="{{ $domain }}"
    data-status="{{ $status }}"
    data-actionable="{{ $definition['actionable'] ? 'true' : 'false' }}"
    data-terminal="{{ $definition['terminal'] ? 'true' : 'false' }}"
>
    <span class="ui-status__icon" aria-hidden="true">{{ $icons[$definition['icon']] ?? $icons['neutral'] }}</span>
    <span>{{ $displayLabel }}</span>
</span>
