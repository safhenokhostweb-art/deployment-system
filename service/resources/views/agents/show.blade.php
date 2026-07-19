@extends('layouts.app')

@section('title', $agent->name ?? $agent->public_id)

@section('content')
<h1>{{ $agent->name ?? 'Unregistered agent' }}</h1>
<p class="muted">Server: <a href="{{ route('servers.show', $agent->server) }}">{{ $agent->server->name }}</a></p>

@if (session('generated_registration_token'))
<div class="card" style="border-color:#a41e1e;">
    <h3>Save these now — shown only once</h3>
    <p class="muted">Hand these to whoever is provisioning the agent (out-of-band). The installer's <code>POST /api/agent/register</code> call needs both, signed with the registration token as the HMAC key. Expires in {{ config('deployment.agent_registration_token_ttl_minutes') }} minutes.</p>
    <label>registration_id</label>
    <div class="secret-box">{{ session('generated_registration_id') }}</div>
    <label>registration_token</label>
    <div class="secret-box">{{ session('generated_registration_token') }}</div>
</div>
@endif

@if (session('generated_token'))
<div class="card" style="border-color:#a41e1e;">
    <h3>Save these now — shown only once</h3>
    <p class="muted">Paste these into <code>config.php</code> on the agent's server (<code>server_id</code> = this agent's <code>public_id</code> below).</p>
    <label>agent public_id (use as server_id in config.php)</label>
    <div class="secret-box">{{ $agent->public_id }}</div>
    <label>agent_token</label>
    <div class="secret-box">{{ session('generated_token') }}</div>
    <label>signing_secret</label>
    <div class="secret-box">{{ session('generated_secret') }}</div>
</div>
@endif

<div class="card">
    <table>
        <tr><th>public_id</th><td><code>{{ $agent->public_id }}</code></td></tr>
        <tr><th>Status</th><td>
            @if ($agent->status === 'approved')
                <span class="badge badge-succeeded">approved</span>
            @elseif ($agent->status === 'disabled')
                <span class="badge badge-failed">disabled</span>
            @else
                <span class="badge badge-pending_approval">{{ $agent->status }}</span>
            @endif
            &mdash;
            @if ($agent->isOnline())
                <span class="badge badge-succeeded">online</span>
            @else
                <span class="badge badge-cancelled">offline</span>
            @endif
        </td></tr>
        <tr><th>Version</th><td>{{ $agent->agent_version ?? 'not reported yet' }}</td></tr>
        <tr><th>OS</th><td>{{ $agent->os ?? '—' }}</td></tr>
        <tr><th>Architecture</th><td>{{ $agent->architecture ?? '—' }}</td></tr>
        <tr><th>IP (last seen from)</th><td>{{ $agent->ip_address ?? '—' }}</td></tr>
        <tr><th>Capabilities</th><td>
            @if ($agent->capabilities)
                <pre>{{ json_encode($agent->capabilities, JSON_PRETTY_PRINT) }}</pre>
            @else
                <span class="muted">not reported yet</span>
            @endif
        </td></tr>
        <tr><th>Last heartbeat</th><td>{{ $agent->last_seen_at?->diffForHumans() ?? 'never' }}</td></tr>
        <tr><th>Registered</th><td>{{ $agent->created_at->diffForHumans() }}</td></tr>
        @if ($agent->approved_by)
        <tr><th>Approved by</th><td>{{ $agent->approved_by }} &mdash; {{ $agent->approved_at?->diffForHumans() }}</td></tr>
        @endif
    </table>

    <div style="margin-top:1rem;display:flex;gap:0.6rem;">
        @if ($agent->status === 'pending_approval')
            <form method="POST" action="{{ route('agents.approve', $agent) }}">
                @csrf
                <button type="submit" class="btn btn-primary" @if(!$agent->name) disabled title="Waiting for the agent's registration call" @endif>Approve</button>
            </form>
            <form method="POST" action="{{ route('agents.reject', $agent) }}" onsubmit="return confirm('Reject this pending agent?');">
                @csrf
                <button type="submit" class="btn btn-danger">Reject</button>
            </form>
        @elseif ($agent->status === 'approved')
            <form method="POST" action="{{ route('agents.disable', $agent) }}" onsubmit="return confirm('Disable this agent? It will be unable to authenticate immediately.');">
                @csrf
                <button type="submit" class="btn btn-danger">Disable</button>
            </form>
        @elseif ($agent->status === 'disabled')
            <form method="POST" action="{{ route('agents.reenable', $agent) }}">
                @csrf
                <button type="submit" class="btn">Re-enable</button>
            </form>
        @endif
    </div>
</div>
@endsection
