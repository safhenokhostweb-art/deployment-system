@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<h1>Dashboard</h1>

<div class="card">
    <h3>Servers</h3>
    <table>
        <thead><tr><th>Name</th><th>Projects</th><th>Last seen</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($servers as $server)
            <tr>
                <td><a href="{{ route('servers.show', $server) }}">{{ $server->name }}</a></td>
                <td>{{ $server->projects_count }}</td>
                <td>{{ $server->last_seen_at?->diffForHumans() ?? 'never' }}</td>
                <td>
                    @if ($server->last_seen_at && $server->last_seen_at->gt(now()->subMinutes(5)))
                        <span class="badge badge-succeeded">online</span>
                    @else
                        <span class="badge badge-cancelled">offline</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No servers registered yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card">
    <h3>Recent deployments</h3>
    <table>
        <thead><tr><th>Project</th><th>Commit</th><th>Action</th><th>Status</th><th>Trigger</th><th>Created</th></tr></thead>
        <tbody>
        @forelse ($recentDeployments as $deployment)
            <tr>
                <td><a href="{{ route('deployments.show', $deployment) }}">{{ $deployment->project->name }}</a></td>
                <td><code>{{ substr($deployment->commit_hash, 0, 10) }}</code></td>
                <td>{{ $deployment->action }}</td>
                <td><span class="badge badge-{{ $deployment->status }}">{{ $deployment->status }}</span></td>
                <td>{{ $deployment->trigger }}</td>
                <td>{{ $deployment->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No deployments yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
