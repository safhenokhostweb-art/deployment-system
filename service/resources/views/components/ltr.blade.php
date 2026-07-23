{{-- Keeps embedded codes/hashes/URLs/numbers left-to-right inside RTL text. --}}
<span dir="ltr" {{ $attributes->merge(['class' => 'ltr-inline']) }}>{{ $slot }}</span>
