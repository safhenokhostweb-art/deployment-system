@extends('layouts.app')

@section('title', 'Pending Agent Approvals')

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>Pending Agent Approvals</h1>
    <a href="{{ route('agents.index') }}" class="btn">All agents</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>public_id</th><th>Server</th><th>Self-reported name</th><th>OS</th><th>Architecture</th><th>Capabilities</th><th>Registered</th><th></th></tr></thead>
        <tbody>
        @forelse ($pending as $agent)
            <tr>
                <td><a href="{{ route('agents.show', $agent) }}"><code>{{ $agent->public_id }}</code></a></td>
                <td><a href="{{ route('servers.show', $agent->server) }}">{{ $agent->server->name }}</a></td>
                <td>{{ $agent->name ?? '(not registered yet)' }}</td>
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
                <td>{{ $agent->created_at->diffForHumans() }}</td>
                <td style="white-space:nowrap;">
                    @if ($agent->name)
                        <form method="POST" action="{{ route('agents.approve', $agent) }}" style="display:inline">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-small">Approve</button>
                        </form>
                        <form method="POST" action="{{ route('agents.reject', $agent) }}" style="display:inline" onsubmit="return confirm('Reject and delete this pending agent?');">
                            @csrf
                            <button type="submit" class="btn btn-danger btn-small">Reject</button>
                        </form>
                    @else
                        <span class="muted">awaiting agent registration call</span>
                        <form method="POST" action="{{ route('agents.reject', $agent) }}" style="display:inline" onsubmit="return confirm('Discard this unused registration token?');">
                            @csrf
                            <button type="submit" class="btn btn-small">Discard</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">No pending agents.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
