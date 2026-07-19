@extends('layouts.app')

@section('title', 'Agents')

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>Agents</h1>
    <a href="{{ route('agents.pending') }}" class="btn btn-primary">Pending approvals</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>Name / public_id</th><th>Server</th><th>Status</th><th>Online</th><th>Version</th><th>OS</th><th>Architecture</th><th>Capabilities</th><th>Last heartbeat</th></tr></thead>
        <tbody>
        @forelse ($agents as $agent)
            <tr>
                <td><a href="{{ route('agents.show', $agent) }}">{{ $agent->name ?? $agent->public_id }}</a></td>
                <td><a href="{{ route('servers.show', $agent->server) }}">{{ $agent->server->name }}</a></td>
                <td>
                    @if ($agent->status === 'approved')
                        <span class="badge badge-succeeded">approved</span>
                    @elseif ($agent->status === 'disabled')
                        <span class="badge badge-failed">disabled</span>
                    @else
                        <span class="badge badge-pending_approval">{{ $agent->status }}</span>
                    @endif
                </td>
                <td>
                    @if ($agent->isOnline())
                        <span class="badge badge-succeeded">online</span>
                    @else
                        <span class="badge badge-cancelled">offline</span>
                    @endif
                </td>
                <td>{{ $agent->agent_version ?? '—' }}</td>
                <td>{{ $agent->os ?? '—' }}</td>
                <td>{{ $agent->architecture ?? '—' }}</td>
                <td class="muted">
                    @if ($agent->capabilities)
                        @foreach ($agent->capabilities as $key => $value)
                            <span class="badge badge-queued" style="margin:0 0.2rem 0.2rem 0;">{{ $key }}: {{ is_bool($value) ? ($value ? 'yes' : 'no') : (is_array($value) ? implode(',', $value) : $value) }}</span>
                        @endforeach
                    @else
                        —
                    @endif
                </td>
                <td>{{ $agent->last_seen_at?->diffForHumans() ?? 'never' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">No approved or disabled agents yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
