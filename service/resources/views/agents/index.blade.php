@extends('layouts.app')

@section('title', __('ui.agents.title'))

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ __('ui.agents.title') }}</h1>
    <a href="{{ route('agents.pending') }}" class="btn btn-primary">{{ __('ui.agents.pending_button') }}</a>
</div>

<x-card>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }} / public_id</th>
            <th>{{ __('ui.common.server') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('status.online') }}</th>
            <th>{{ __('ui.common.version') }}</th>
            <th>{{ __('ui.agents.os') }}</th>
            <th>{{ __('ui.agents.architecture') }}</th>
            <th>{{ __('ui.agents.capabilities') }}</th>
            <th>{{ __('ui.common.last_heartbeat') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($agents as $agent)
            <tr>
                <td><a href="{{ route('agents.show', $agent) }}">{{ $agent->name ?? $agent->public_id }}</a></td>
                <td><a href="{{ route('servers.show', $agent->server) }}">{{ $agent->server->name }}</a></td>
                <td><x-badge :status="$agent->status" /></td>
                <td>
                    @if ($agent->isOnline())
                        <x-badge status="online" />
                    @else
                        <x-badge status="offline" />
                    @endif
                </td>
                <td>{{ $agent->agent_version ?? '—' }}</td>
                <td>{{ $agent->os ?? '—' }}</td>
                <td>{{ $agent->architecture ?? '—' }}</td>
                <td class="muted">
                    @if ($agent->capabilities)
                        @foreach ($agent->capabilities as $key => $value)
                            <span class="badge badge-queued" style="margin:0 0.2rem 0.2rem 0;"><x-ltr>{{ $key }}: {{ is_bool($value) ? ($value ? __('status.yes') : __('status.no')) : (is_array($value) ? implode(',', $value) : $value) }}</x-ltr></span>
                        @endforeach
                    @else
                        —
                    @endif
                </td>
                <td>{{ $agent->last_seen_at?->diffForHumans() ?? __('status.never') }}</td>
            </tr>
        @empty
            <tr><td colspan="9"><x-empty-state>{{ __('ui.agents.no_agents') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
