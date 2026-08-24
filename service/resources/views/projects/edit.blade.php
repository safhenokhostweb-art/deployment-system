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

        <label for="authoritative_build_local_agent_id">Authoritative production build Agent</label>
        <select id="authoritative_build_local_agent_id" name="authoritative_build_local_agent_id">
            <option value="">— Not configured (artifact builds blocked) —</option>
            @foreach ($buildAgentCandidates as $candidate)
                <option value="{{ $candidate->id }}" {{ (int) old('authoritative_build_local_agent_id', $project->authoritative_build_local_agent_id) === $candidate->id ? 'selected' : '' }}>
                    {{ $candidate->name ?? $candidate->public_id }} — {{ $candidate->role }} — {{ $candidate->isOnline() ? 'Online' : 'Offline' }} — v{{ $candidate->agent_version ?? 'unknown' }}
                </option>
            @endforeach
        </select>
        <p class="muted">Artifact-required production builds use only this Agent. There is no automatic fallback.</p>

        @php($health = $project->http_health_check ?? [])
        <input type="hidden" name="http_health_enabled" value="0">
        <label><input type="checkbox" name="http_health_enabled" value="1" style="width:auto;display:inline-block;" {{ old('http_health_enabled', $health !== []) ? 'checked' : '' }}> Require customer-facing HTTP health verification</label>
        <label for="http_health_base_url">Health base URL</label>
        <input type="url" id="http_health_base_url" name="http_health_base_url" value="{{ old('http_health_base_url', $health['base_url'] ?? '') }}" dir="ltr" style="text-align:left;" placeholder="https://example.com">
        <label for="http_health_path">Health path</label>
        <input type="text" id="http_health_path" name="http_health_path" value="{{ old('http_health_path', $health['path'] ?? '/') }}" dir="ltr" style="text-align:left;">
        <label for="http_health_expected_status">Expected HTTP status</label>
        <input type="number" id="http_health_expected_status" name="http_health_expected_status" value="{{ old('http_health_expected_status', $health['expected_status'] ?? 200) }}" min="100" max="599">
        <label for="http_health_timeout_seconds">Timeout seconds</label>
        <input type="number" id="http_health_timeout_seconds" name="http_health_timeout_seconds" value="{{ old('http_health_timeout_seconds', $health['timeout_seconds'] ?? 15) }}" min="1" max="120">
        <label for="http_health_retries">Retry count</label>
        <input type="number" id="http_health_retries" name="http_health_retries" value="{{ old('http_health_retries', $health['retries'] ?? 3) }}" min="0" max="10">
        <label for="http_health_retry_interval_seconds">Retry interval seconds</label>
        <input type="number" id="http_health_retry_interval_seconds" name="http_health_retry_interval_seconds" value="{{ old('http_health_retry_interval_seconds', $health['retry_interval_seconds'] ?? 5) }}" min="0" max="300">
        <input type="hidden" name="http_health_allowed_redirects" value="0">
        <label><input type="checkbox" name="http_health_allowed_redirects" value="1" style="width:auto;display:inline-block;" {{ old('http_health_allowed_redirects', $health['allowed_redirects'] ?? false) ? 'checked' : '' }}> Allow redirects (maximum 5)</label>
        <label for="http_health_expected_content">Optional expected content marker</label>
        <input type="text" id="http_health_expected_content" name="http_health_expected_content" value="{{ old('http_health_expected_content', $health['expected_content'] ?? '') }}">

        <label for="artifact_preserve_paths_text">Artifact preserve paths</label>
        <textarea id="artifact_preserve_paths_text" name="artifact_preserve_paths_text" rows="4" dir="ltr" style="text-align:left;">{{ old('artifact_preserve_paths_text', implode("\n", $project->artifact_preserve_paths ?? [])) }}</textarea>
        <p class="muted">One relative runtime path per line. Paths must not be absolute or contain <code>..</code>.</p>

        <label for="artifact_persistent_paths_text">Artifact persistent paths</label>
        <textarea id="artifact_persistent_paths_text" name="artifact_persistent_paths_text" rows="4" dir="ltr" style="text-align:left;">{{ old('artifact_persistent_paths_text', implode("\n", $project->artifact_persistent_paths ?? [])) }}</textarea>
        <p class="muted">One relative runtime path per line — symlinked from the persistent root into each release instead of copied. Must not overlap preserve paths.</p>

        <label for="artifact_persistent_root">Artifact persistent root</label>
        <input type="text" id="artifact_persistent_root" name="artifact_persistent_root" value="{{ old('artifact_persistent_root', $project->artifact_persistent_root) }}" dir="ltr" style="text-align:left;">
        <p class="muted">Absolute directory on the target server holding the persistent state (configured there in agent config.php; the Hub never learns server paths).</p>

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
