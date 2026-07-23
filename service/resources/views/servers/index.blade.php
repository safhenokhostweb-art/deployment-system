@extends('layouts.app')

@section('title', __('ui.servers.title'))

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ __('ui.servers.title') }}</h1>
    @can('servers.create')
    <a href="{{ route('servers.create') }}" class="btn btn-primary">{{ __('ui.servers.register_button') }}</a>
    @endcan
</div>

<x-card>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.servers.agent_version') }}</th>
            <th>{{ __('ui.common.description') }}</th>
            <th>{{ __('ui.common.projects') }}</th>
            <th>{{ __('ui.common.last_seen') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($servers as $server)
            <tr>
                <td><a href="{{ route('servers.show', $server) }}">{{ $server->name }}</a></td>
                <td>
                    @if ($server->isOnline())
                        <x-badge status="online" />
                    @else
                        <x-badge status="offline" />
                    @endif
                </td>
                <td>{{ $server->agent_version ?? '—' }}</td>
                <td>{{ $server->description }}</td>
                <td>{{ $server->projects_count }}</td>
                <td>{{ $server->last_seen_at?->diffForHumans() ?? __('status.never') }}</td>
            </tr>
        @empty
            <tr><td colspan="6"><x-empty-state>{{ __('ui.servers.no_servers') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
