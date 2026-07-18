@extends('layouts.app')

@section('title', 'Edit ' . $project->name)

@section('content')
<h1>Edit {{ $project->name }}</h1>

<div class="card">
    <form method="POST" action="{{ route('projects.update', $project) }}">
        @csrf
        @method('PUT')

        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="{{ old('name', $project->name) }}" required>

        <label>Project key</label>
        <input type="text" value="{{ $project->project_key }}" disabled>
        <p class="muted">Project key cannot be changed after creation.</p>

        <label for="repository">GitHub repository</label>
        <input type="text" id="repository" name="repository" value="{{ old('repository', $project->repository) }}" required>

        <label for="branch">Branch to deploy</label>
        <input type="text" id="branch" name="branch" value="{{ old('branch', $project->branch) }}" required>

        <label for="environment">Environment</label>
        <input type="text" id="environment" name="environment" value="{{ old('environment', $project->environment) }}" required>

        <label for="server_id">Server</label>
        <select id="server_id" name="server_id" required>
            @foreach ($servers as $server)
                <option value="{{ $server->id }}" {{ $project->server_id === $server->id ? 'selected' : '' }}>{{ $server->name }}</option>
            @endforeach
        </select>

        <label for="pipeline">Pipeline (JSON array of steps)</label>
        <textarea id="pipeline" name="pipeline" rows="12">{{ old('pipeline', $pipelineJson) }}</textarea>

        <label>
            <input type="checkbox" name="auto_deploy" value="1" style="width:auto;display:inline-block;" {{ old('auto_deploy', $project->auto_deploy) ? 'checked' : '' }}>
            Auto-deploy on push (skip manual approval)
        </label>

        <label>
            <input type="checkbox" name="is_active" value="1" style="width:auto;display:inline-block;" {{ old('is_active', $project->is_active) ? 'checked' : '' }}>
            Active (unchecking stops accepting webhooks for this project)
        </label>

        <button type="submit" class="btn btn-primary" style="margin-top: 1.2rem;">Save</button>
    </form>
</div>
@endsection
