@props(['current', 'labels'])
<div class="wizard-steps">
    @foreach ($labels as $index => $label)
        @php $step = $index + 1; @endphp
        <div class="wizard-step {{ $step < $current ? 'done' : ($step === $current ? 'active' : '') }}">
            <span class="wizard-step-number">{{ $step }}</span>
            <span class="wizard-step-label">{{ $label }}</span>
        </div>
    @endforeach
</div>
