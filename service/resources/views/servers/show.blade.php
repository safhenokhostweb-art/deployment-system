@extends('layouts.app')

@section('title', $server->name)

@section('content')
<h1>{{ $server->name }}</h1>
<p class="muted">{{ $server->description }}</p>

@if (session('generated_token'))
<div class="card" style="border-color:#a41e1e;">
    <h3>Save these now — shown only once</h3>
    <p class="muted">Paste these into <code>config.php</code> on the target server (see agent/README.md). If you lose them, use "Rotate credentials" below to issue new ones.</p>
    <label>server_id</label>
    <div class="secret-box">{{ $server->public_id }}</div>
    <label>agent_token</label>
    <div class="secret-box">{{ session('generated_token') }}</div>
    <label>signing_secret</label>
    <div class="secret-box">{{ session('generated_secret') }}</div>
</div>
@endif

<div class="card">
    <table>
        <tr><th>server_id</th><td><code>{{ $server->public_id }}</code></td></tr>
        <tr><th>Status</th><td>{{ $server->is_active ? 'active' : 'disabled' }} &mdash;
            @if ($server->isOnline())
                <span class="badge badge-succeeded">online</span>
            @else
                <span class="badge badge-cancelled">offline</span>
            @endif
        </td></tr>
        <tr><th>Agent version</th><td>{{ $server->agent_version ?? 'not reported yet' }}</td></tr>
        <tr><th>Last seen</th><td>{{ $server->last_seen_at?->diffForHumans() ?? 'never polled yet' }}</td></tr>
        <tr><th>Registered</th><td>{{ $server->created_at->diffForHumans() }}</td></tr>
    </table>

    <form method="POST" action="{{ route('servers.rotate-token', $server) }}" style="margin-top:1rem;" onsubmit="return confirm('Rotating invalidates the current agent_token and signing_secret immediately. The agent on this server will stop authenticating until config.php is updated. Continue?');">
        @csrf
        <button type="submit" class="btn btn-danger btn-small">Rotate credentials</button>
    </form>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h3>Agents on this server</h3>
        <form method="POST" action="{{ route('agents.create-registration', $server) }}">
            @csrf
            <button type="submit" class="btn btn-small">Register new agent</button>
        </form>
    </div>
    <p class="muted">Optional going forward — this server keeps authenticating exactly as it does today via its own credentials above whether or not any Agent is registered here.</p>
    <table>
        <thead><tr><th>Name / public_id</th><th>Status</th><th>Version</th><th>Last heartbeat</th></tr></thead>
        <tbody>
        @forelse ($server->agents as $agent)
            <tr>
                <td><a href="{{ route('agents.show', $agent) }}">{{ $agent->name ?? $agent->public_id }}</a></td>
                <td>{{ $agent->status }}</td>
                <td>{{ $agent->agent_version ?? '—' }}</td>
                <td>{{ $agent->last_seen_at?->diffForHumans() ?? 'never' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No agents registered for this server yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card">
    <h3>Projects on this server</h3>
    <table>
        <thead><tr><th>Name</th><th>Repository</th><th>Branch</th><th>Environment</th></tr></thead>
        <tbody>
        @forelse ($server->projects as $project)
            <tr>
                <td><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></td>
                <td>{{ $project->repository }}</td>
                <td>{{ $project->branch }}</td>
                <td>{{ $project->environment }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No projects assigned to this server yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
