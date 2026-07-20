@extends('layouts.app')

@section('title', 'Target ' . $task->deploymentTarget->name)

@section('content')
<h1>{{ $task->deploymentTarget->name }} &mdash; <span class="badge badge-{{ $task->status }}">{{ $task->status }}</span></h1>
<p class="muted">
    Deployment: <a href="{{ route('deployments.show', $task->deployment) }}">{{ $task->deployment->project->name }} @ {{ substr($task->deployment->commit_hash, 0, 12) }}</a>
    &mdash; Environment: {{ $task->deploymentTarget->environment->name }}
    &mdash; Server: <a href="{{ route('servers.show', $task->deploymentTarget->server) }}">{{ $task->deploymentTarget->server->name }}</a>
    @if ($task->next_retry_at)
        &mdash; <span class="badge badge-queued">retry scheduled {{ $task->next_retry_at->diffForHumans() }}</span>
    @endif
</p>

@if ($task->status === 'succeeded')
<div class="card">
    <form method="POST" action="{{ route('deployment-tasks.rollback', $task) }}" onsubmit="return confirm('Roll back only this target ({{ $task->deploymentTarget->name }}) to this commit? Other targets in this environment are not affected.');">
        @csrf
        <button type="submit" class="btn">Roll back this target to this commit</button>
    </form>

    <h3 style="margin-top:1.5rem;">Health check</h3>
    <form method="POST" action="{{ route('deployment-tasks.health-check', $task) }}" style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap;">
        @csrf
        <div style="flex:1;min-width:220px;">
            <label style="margin-top:0;">URL to check</label>
            <input type="text" name="url" placeholder="https://example.com/health" required>
        </div>
        <div style="width:120px;">
            <label style="margin-top:0;">Expected status</label>
            <input type="text" name="expected_status" value="200">
        </div>
        <button type="submit" class="btn btn-primary">Run check</button>
    </form>

    @forelse ($task->healthChecks as $check)
        <p style="margin:0.6rem 0 0;">
            <span class="badge badge-{{ $check->status === 'success' ? 'succeeded' : ($check->status === 'failed' ? 'failed' : 'pending_approval') }}">{{ $check->status }}</span>
            <span class="muted">{{ $check->type }} — {{ $check->result_message }} ({{ $check->checked_at?->diffForHumans() ?? 'pending' }})</span>
        </p>
    @empty
        <p class="muted" style="margin-top:0.6rem;">No health checks run yet.</p>
    @endforelse
</div>
@endif

<div class="card">
    <h3>Attempts</h3>
    @forelse ($task->agentJobs as $agentJob)
        <div style="margin-bottom:1.2rem;padding-bottom:1rem;border-bottom:1px solid #eee;">
            <h4 style="margin-bottom:0.3rem;">
                Attempt {{ $agentJob->attempt }}
                <span class="badge badge-{{ $agentJob->status }}">{{ $agentJob->status }}</span>
                <span class="muted">— agent: {{ $agentJob->agent->name ?? $agentJob->agent->public_id }}</span>
            </h4>
            <p class="muted" style="margin:0 0 0.5rem;">
                Started {{ $agentJob->started_at?->diffForHumans() ?? '—' }}
                @if ($agentJob->finished_at) &mdash; finished {{ $agentJob->finished_at->diffForHumans() }} @endif
            </p>

            @forelse ($agentJob->logEntries as $entry)
                <div style="margin-bottom:0.6rem;">
                    <strong>{{ $entry->sequence }}. {{ $entry->step_name }}</strong>
                    @if ($entry->exit_code === 0)
                        <span class="badge badge-succeeded">exit 0</span>
                    @elseif ($entry->exit_code !== null)
                        <span class="badge badge-failed">exit {{ $entry->exit_code }}</span>
                    @endif
                    <span class="muted">({{ $entry->duration_ms }}ms)</span>
                    <p class="muted" style="margin:0.2rem 0;"><code>{{ $entry->command }}</code></p>
                    <pre>{{ $entry->output }}</pre>
                </div>
            @empty
                <p class="muted">No log entries for this attempt yet.</p>
            @endforelse
        </div>
    @empty
        <p class="muted">No attempts yet — no agent has claimed this target's task.</p>
    @endforelse
</div>
@endsection
