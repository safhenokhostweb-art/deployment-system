@props(['type' => 'success'])
<div {{ $attributes->merge(['class' => $type === 'error' ? 'error-msg' : 'status-msg']) }}>
    {{ $slot }}
</div>
