@extends('layouts.app')

@section('title', 'New project')

@section('content')
<h1>New project</h1>

@if ($servers->isEmpty())
    <div class="card">
        <p>You need to <a href="{{ route('servers.create') }}">register a server</a> before creating a project.</p>
    </div>
@else
<div class="card">
    <form method="POST" action="{{ route('projects.store') }}">
        @csrf

        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus>

        <label for="project_key">Project key</label>
        <input type="text" id="project_key" name="project_key" value="{{ old('project_key') }}" placeholder="e.g. saas-platform-production" pattern="[a-z0-9-]+" required>
        <p class="muted">Lowercase letters, numbers and hyphens only. Must match a key in that server's agent config.php <code>projects</code> map.</p>

        <label for="repository">GitHub repository</label>
        <input type="text" id="repository" name="repository" value="{{ old('repository') }}" placeholder="owner/repo" required>

        <label for="branch">Branch to deploy</label>
        <input type="text" id="branch" name="branch" value="{{ old('branch', 'main') }}" required>

        <label for="environment">Environment</label>
        <input type="text" id="environment" name="environment" value="{{ old('environment', 'production') }}" required>

        <label for="server_id">Server</label>
        <select id="server_id" name="server_id" required>
            @foreach ($servers as $server)
                <option value="{{ $server->id }}">{{ $server->name }}</option>
            @endforeach
        </select>

        <label for="pipeline">Pipeline (JSON array of steps, {branch}/{commit}/{working_directory}/{php_binary} placeholders available)</label>
        <textarea id="pipeline" name="pipeline" rows="12">{{ old('pipeline', $defaultPipeline) }}</textarea>

        <label>
            <input type="checkbox" name="auto_deploy" value="1" style="width:auto;display:inline-block;" {{ old('auto_deploy') ? 'checked' : '' }}>
            Auto-deploy on push (skip manual approval)
        </label>

        <button type="submit" class="btn btn-primary" style="margin-top: 1.2rem;">Create project</button>
    </form>
</div>
@endif
@endsection
