@extends('layouts.app')

@section('title', __('ui.notifications.title'))

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ __('ui.notifications.title') }}</h1>
    <a href="{{ route('notifications.preferences') }}" class="btn btn-small">{{ __('ui.notifications.preferences_link') }}</a>
</div>

<x-card>
    <form method="GET" action="{{ route('notifications.index') }}" style="display:flex;gap:0.8rem;align-items:flex-end;flex-wrap:wrap;">
        <div style="min-width:200px;">
            <label for="type" style="margin-top:0;">{{ __('ui.notifications.filter_type_label') }}</label>
            <select id="type" name="type">
                <option value="">{{ __('ui.notifications.filter_type_all') }}</option>
                @foreach ($types as $type)
                    <option value="{{ $type }}" {{ request('type') === $type ? 'selected' : '' }}>{{ __('ui.notifications.type_' . $type) }}</option>
                @endforeach
            </select>
        </div>
        <div style="min-width:160px;">
            <label for="severity" style="margin-top:0;">{{ __('ui.notifications.filter_severity_label') }}</label>
            <select id="severity" name="severity">
                <option value="">{{ __('ui.notifications.filter_severity_all') }}</option>
                @foreach ($severities as $severity)
                    <option value="{{ $severity }}" {{ request('severity') === $severity ? 'selected' : '' }}>{{ __('ui.notifications.severity_' . $severity) }}</option>
                @endforeach
            </select>
        </div>
        <div style="min-width:160px;">
            <label for="status" style="margin-top:0;">{{ __('ui.notifications.filter_status_label') }}</label>
            <select id="status" name="status">
                <option value="">{{ __('ui.notifications.filter_status_all') }}</option>
                <option value="unread" {{ request('status') === 'unread' ? 'selected' : '' }}>{{ __('ui.notifications.filter_status_unread') }}</option>
            </select>
        </div>
        <button type="submit" class="btn">{{ __('ui.common.confirm_action') }}</button>

        @if ($unreadCount > 0)
        <form method="POST" action="{{ route('notifications.read-all') }}" style="margin-inline-start:auto;">
            @csrf
            <button type="submit" class="btn btn-small">{{ __('ui.notifications.mark_all_read_button') }}</button>
        </form>
        @endif
    </form>
</x-card>

<x-card>
    @forelse ($notifications as $notification)
        @if ($loop->first)<ul class="notification-list">@endif
        @php $relatedUrl = $relatedUrls[$notification->id] ?? null; @endphp
        <li class="notification-item severity-{{ $notification->severity }} {{ $notification->isRead() ? '' : 'is-unread' }}">
            <span class="severity-dot" title="{{ __('ui.notifications.severity_' . $notification->severity) }}"></span>
            <div class="notification-body">
                <div class="notification-title">{{ $notification->title }}</div>
                <p class="muted" style="margin:0.2rem 0;">{{ $notification->message }}</p>
                <span class="muted" style="font-size:0.78rem;">{{ $notification->created_at->diffForHumans() }}</span>
            </div>
            <div class="notification-actions">
                @if ($relatedUrl)
                <a href="{{ $relatedUrl }}" class="btn btn-small">{{ __('ui.notifications.view_related_button') }}</a>
                @endif
                @unless ($notification->isRead())
                <form method="POST" action="{{ route('notifications.read', $notification) }}">
                    @csrf
                    <button type="submit" class="btn btn-small">{{ __('ui.notifications.mark_read_button') }}</button>
                </form>
                @endunless
            </div>
        </li>
        @if ($loop->last)</ul>@endif
    @empty
        <x-empty-state>{{ __('ui.notifications.no_notifications') }}</x-empty-state>
    @endforelse
</x-card>
@endsection
