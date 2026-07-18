@extends('layouts.app')

@section('title', $project->name)

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ $project->name }}</h1>
    <a href="{{ route('projects.edit', $project) }}" class="btn">Edit</a>
</div>

<div class="card">
    <table>
        <tr><th>Repository</th><td>{{ $project->repository }}</td></tr>
        <tr><th>Branch</th><td>{{ $project->branch }}</td></tr>
        <tr><th>Environment</th><td>{{ $project->environment }}</td></tr>
        <tr><th>Server</th><td><a href="{{ route('servers.show', $project->server) }}">{{ $project->server->name }}</a></td></tr>
        <tr><th>Auto-deploy</th><td>{{ $project->auto_deploy ? 'yes' : 'no — pushes wait for manual approval' }}</td></tr>
        <tr><th>project_key</th><td><code>{{ $project->project_key }}</code> (must match this server's agent config.php)</td></tr>
    </table>
</div>

<div class="card">
    <h3>GitHub webhook setup</h3>
    <p class="muted">In the repository's Settings &rarr; Webhooks &rarr; Add webhook:</p>
    <label>Payload URL</label>
    <div class="secret-box">{{ $webhookUrl }}</div>
    <label>Secret</label>
    <div class="secret-box">{{ $project->github_webhook_secret }}</div>
    <p class="muted">Content type: <code>application/json</code>. Events: just the <code>push</code> event.</p>
</div>

<div class="card">
    <h3>Pipeline</h3>
    <pre>{{ json_encode($project->pipeline, JSON_PRETTY_PRINT) }}</pre>
</div>

<div class="card">
    <h3>Deploy a specific commit manually</h3>
    <form method="POST" action="{{ route('projects.deploy', $project) }}">
        @csrf
        <input type="text" name="commit_hash" placeholder="40-character commit SHA" required>
        <button type="submit" class="btn btn-primary" style="margin-top:0.6rem;">Queue deployment</button>
    </form>
</div>

<div class="card">
    <h3>Deployment history</h3>
    <table>
        <thead><tr><th>Commit</th><th>Action</th><th>Status</th><th>Trigger</th><th>Created</th><th></th></tr></thead>
        <tbody>
        @forelse ($deployments as $deployment)
            <tr>
                <td><code>{{ substr($deployment->commit_hash, 0, 10) }}</code></td>
                <td>{{ $deployment->action }}</td>
                <td><span class="badge badge-{{ $deployment->status }}">{{ $deployment->status }}</span></td>
                <td>{{ $deployment->trigger }}</td>
                <td>{{ $deployment->created_at->diffForHumans() }}</td>
                <td><a href="{{ route('deployments.show', $deployment) }}" class="btn btn-small">View</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No deployments yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
