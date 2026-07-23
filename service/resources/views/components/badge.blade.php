@props(['status'])
<span {{ $attributes->merge(['class' => 'badge badge-' . $status]) }}>{{ __('status.' . $status) }}</span>
