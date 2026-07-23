@extends('layouts.app')

@section('title', __('ui.notifications.preferences_title'))

@section('content')
<h1>{{ __('ui.notifications.preferences_title') }}</h1>
<p class="muted" style="margin-top:-0.6rem;">{{ __('ui.notifications.preferences_intro') }}</p>

<x-card>
    <form method="POST" action="{{ route('notifications.preferences.update') }}">
        @csrf
        @php
            $groups = [];
            foreach (\App\Http\Controllers\Dashboard\NotificationController::notificationTypeGroups() as $type => $group) {
                $groups[$group][] = $type;
            }
        @endphp
        @foreach ($groups as $group => $types)
            <h4 style="margin-top:1.2rem;margin-bottom:0.4rem;">{{ __('ui.notifications.preferences_group_' . $group) }}</h4>
            @foreach ($types as $type)
                <label style="display:flex;align-items:center;gap:0.5rem;font-weight:normal;margin-top:0.4rem;">
                    <input type="checkbox" name="enabled[]" value="{{ $type }}" style="width:auto;" {{ $user->wantsNotification($type) ? 'checked' : '' }}>
                    {{ __('ui.notifications.type_' . $type) }}
                </label>
            @endforeach
        @endforeach

        <button type="submit" class="btn btn-primary" style="margin-top:1.2rem;">{{ __('ui.notifications.preferences_save_button') }}</button>
    </form>
</x-card>
@endsection
