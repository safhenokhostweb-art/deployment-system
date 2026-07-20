@extends('layouts.app')

@section('title', 'Deployment ' . $deployment->public_id)

@section('content')
<h1>{{ $deployment->project->name }} &mdash; <span class="badge badge-{{ $deployment->status }}">{{ $deployment->status }}</span></h1>

<div class="card">
    <table>
        <tr><th>Deployment ID</th><td><code>{{ $deployment->public_id }}</code></td></tr>
        <tr><th>Commit</th><td><code>{{ $deployment->commit_hash }}</code></td></tr>
        @if ($deployment->previous_commit_hash)
        <tr><th>Previous commit</th><td><code>{{ $deployment->previous_commit_hash }}</code></td></tr>
        @endif
        <tr><th>Branch</th><td>{{ $deployment->branch }}</td></tr>
        <tr><th>Action</th><td>{{ $deployment->action }}</td></tr>
        <tr><th>Trigger</th><td>{{ $deployment->trigger }} @if($deployment->triggered_by) by {{ $deployment->triggered_by }} @endif</td></tr>
        @if ($deployment->rollbackOf)
        <tr><th>Rollback of</th><td><a href="{{ route('deployments.show', $deployment->rollbackOf) }}">deployment {{ $deployment->rollbackOf->public_id }}</a></td></tr>
        @endif
        @php $rollbackDeployment = \App\Models\Deployment::where('rollback_of_deployment_id', $deployment->id)->latest()->first(); @endphp
        @if ($rollbackDeployment)
        <tr><th>Rollback status</th><td>Rolled back by <a href="{{ route('deployments.show', $rollbackDeployment) }}">{{ $rollbackDeployment->public_id }}</a> (<span class="badge badge-{{ $rollbackDeployment->status }}">{{ $rollbackDeployment->status }}</span>)</td></tr>
        @endif
        @if ($deployment->approved_by)
        <tr><th>Approved by</th><td>{{ $deployment->approved_by }} at {{ $deployment->approved_at }}</td></tr>
        @endif
        <tr><th>Server</th><td><a href="{{ route('servers.show', $deployment->server) }}">{{ $deployment->server->name }}</a></td></tr>
        <tr><th>Queued</th><td>{{ $deployment->queued_at?->diffForHumans() ?? '—' }}</td></tr>
        <tr><th>Started</th><td>{{ $deployment->started_at?->diffForHumans() ?? '—' }}</td></tr>
        <tr><th>Finished</th><td>{{ $deployment->finished_at?->diffForHumans() ?? '—' }}</td></tr>
        @if ($deployment->failure_message)
        <tr><th>Failure</th><td>{{ $deployment->failure_message }}</td></tr>
        @endif
    </table>

    <div style="margin-top:1rem;display:flex;gap:0.6rem;">
        @if ($deployment->status === 'pending_approval')
            <form method="POST" action="{{ route('deployments.approve', $deployment) }}">
                @csrf
                <button type="submit" class="btn btn-primary">Approve &amp; queue</button>
            </form>
            <form method="POST" action="{{ route('deployments.cancel', $deployment) }}">
                @csrf
                <button type="submit" class="btn btn-danger">Cancel</button>
            </form>
        @elseif ($deployment->status === 'queued')
            <form method="POST" action="{{ route('deployments.cancel', $deployment) }}">
                @csrf
                <button type="submit" class="btn btn-danger">Cancel</button>
            </form>
        @elseif ($deployment->status === 'succeeded')
            <form method="POST" action="{{ route('deployments.rollback', $deployment) }}" onsubmit="return confirm('Roll back to this commit? This queues a new deployment targeting it.');">
                @csrf
                <button type="submit" class="btn">Roll back to this commit</button>
            </form>
        @endif
    </div>
</div>

@if ($deployment->tasks->isNotEmpty())
<div class="card">
    <h3>Targets ({{ $deployment->project->environments->first()?->name ?? 'environment' }})</h3>
    <table>
        <thead><tr><th>Target</th><th>Server</th><th>Status</th><th>Agent</th><th>Attempts</th><th></th></tr></thead>
        <tbody>
        @foreach ($deployment->tasks as $task)
            <tr>
                <td>{{ $task->deploymentTarget->name }}</td>
                <td><a href="{{ route('servers.show', $task->deploymentTarget->server) }}">{{ $task->deploymentTarget->server->name }}</a></td>
                <td>
                    <span class="badge badge-{{ $task->status }}">{{ $task->status }}</span>
                    @if ($task->next_retry_at)
                        <span class="badge badge-queued">retry {{ $task->next_retry_at->diffForHumans() }}</span>
                    @endif
                </td>
                <td>
                    @php $lastJob = $task->agentJobs->last(); @endphp
                    @if ($lastJob)
                        {{ $lastJob->agent->name ?? $lastJob->agent->public_id }}
                    @else
                        <span class="muted">not claimed yet</span>
                    @endif
                </td>
                <td>{{ $task->agentJobs->count() }}</td>
                <td><a href="{{ route('deployment-tasks.show', $task) }}">Details →</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="card">
    <h3>Pipeline log</h3>
    @forelse ($deployment->logEntries as $entry)
        <h4 style="margin-bottom:0.2rem;">
            {{ $entry->sequence }}. {{ $entry->step_name }}
            @if ($entry->exit_code === 0)
                <span class="badge badge-succeeded">exit 0</span>
            @elseif ($entry->exit_code !== null)
                <span class="badge badge-failed">exit {{ $entry->exit_code }}</span>
            @endif
            @if ($entry->timed_out)
                <span class="badge badge-failed">timed out</span>
            @endif
            <span class="muted">({{ $entry->duration_ms }}ms)</span>
        </h4>
        <p class="muted" style="margin-top:0;"><code>{{ $entry->command }}</code></p>
        <pre>{{ $entry->output }}</pre>
    @empty
        <p class="muted">No log entries yet — the agent hasn't picked this job up.</p>
    @endforelse
</div>
@endsection
