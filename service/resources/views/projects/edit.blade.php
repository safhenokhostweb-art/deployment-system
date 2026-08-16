@extends('layouts.app')

@section('title', __('ui.projects.edit_title', ['name' => $project->name]))

@section('content')
<h1>{{ __('ui.projects.edit_title', ['name' => $project->name]) }}</h1>

<x-card>
    <form method="POST" action="{{ route('projects.update', $project) }}">
        @csrf
        @method('PUT')

        <label for="name">{{ __('ui.common.name') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name', $project->name) }}" required>

        <label>{{ __('ui.projects.project_key') }}</label>
        <input type="text" value="{{ $project->project_key }}" disabled dir="ltr" style="text-align:left;">
        <p class="muted">{{ __('ui.projects.project_key_locked') }}</p>

        <label for="repository">{{ __('ui.common.repository') }} (GitHub)</label>
        <input type="text" id="repository" name="repository" value="{{ old('repository', $project->repository) }}" required>

        <label for="branch">{{ __('ui.common.branch') }}</label>
        <input type="text" id="branch" name="branch" value="{{ old('branch', $project->branch) }}" required>

        <label for="application_root">Application root</label>
        <input type="text" id="application_root" name="application_root" value="{{ old('application_root', $project->application_root ?? '.') }}" dir="ltr" style="text-align:left;">
        <p class="muted">Relative to repository root. Use <code>.</code> for a repository-root application.</p>

        <label for="environment">{{ __('ui.common.environment') }}</label>
        <input type="text" id="environment" name="environment" value="{{ old('environment', $project->environment) }}" required>

        <label for="server_id">{{ __('ui.common.server') }}</label>
        <select id="server_id" name="server_id" required>
            @foreach ($servers as $server)
                <option value="{{ $server->id }}" {{ $project->server_id === $server->id ? 'selected' : '' }}>{{ $server->name }}</option>
            @endforeach
        </select>

        <label for="pipeline">{{ __('ui.projects.pipeline') }}</label>
        <textarea id="pipeline" name="pipeline" rows="12" dir="ltr" style="text-align:left;">{{ old('pipeline', $pipelineJson) }}</textarea>

        <label for="artifact_preserve_paths_text">Artifact preserve paths</label>
        <textarea id="artifact_preserve_paths_text" name="artifact_preserve_paths_text" rows="4" dir="ltr" style="text-align:left;">{{ old('artifact_preserve_paths_text', implode("\n", $project->artifact_preserve_paths ?? [])) }}</textarea>
        <p class="muted">One relative runtime path per line. Paths must not be absolute or contain <code>..</code>.</p>

        <input type="hidden" name="requires_complete_artifact" value="0">
        <label>
            <input type="checkbox" name="requires_complete_artifact" value="1" style="width:auto;display:inline-block;" {{ old('requires_complete_artifact', $project->requires_complete_artifact) ? 'checked' : '' }}>
            Require complete artifact
        </label>

        <label>
            <input type="checkbox" name="auto_deploy" value="1" style="width:auto;display:inline-block;" {{ old('auto_deploy', $project->auto_deploy) ? 'checked' : '' }}>
            {{ __('ui.projects.auto_deploy_label') }}
        </label>

        <label>
            <input type="checkbox" name="is_active" value="1" style="width:auto;display:inline-block;" {{ old('is_active', $project->is_active) ? 'checked' : '' }}>
            {{ __('ui.projects.active_label') }}
        </label>

        <button type="submit" class="btn btn-primary" style="margin-top: 1.2rem;">{{ __('ui.common.save') }}</button>
    </form>
</x-card>
@endsection
