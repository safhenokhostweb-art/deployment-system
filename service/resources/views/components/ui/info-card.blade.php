@props(['title' => null, 'description' => null])
<section {{ $attributes->class(['ui-card']) }}>
    @if ($title || $description || isset($actions))
        <header class="ui-card__header">
            <div>
                @if ($title)<h2 class="ui-card__title">{{ $title }}</h2>@endif
                @if ($description)<p class="ui-card__description">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="ui-card__actions">{{ $actions }}</div>@endisset
        </header>
    @endif
    {{ $slot }}
</section>
