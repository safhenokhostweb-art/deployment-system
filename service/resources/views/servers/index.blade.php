@extends('layouts.app')

@section('title', 'Servers')

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>Servers</h1>
    <a href="{{ route('servers.create') }}" class="btn btn-primary">Register server</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Status</th><th>Agent version</th><th>Description</th><th>Projects</th><th>Last seen</th></tr></thead>
        <tbody>
        @forelse ($servers as $server)
            <tr>
                <td><a href="{{ route('servers.show', $server) }}">{{ $server->name }}</a></td>
                <td>
                    @if ($server->isOnline())
                        <span class="badge badge-succeeded">online</span>
                    @else
                        <span class="badge badge-cancelled">offline</span>
                    @endif
                </td>
                <td>{{ $server->agent_version ?? '—' }}</td>
                <td>{{ $server->description }}</td>
                <td>{{ $server->projects_count }}</td>
                <td>{{ $server->last_seen_at?->diffForHumans() ?? 'never' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No servers registered yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
