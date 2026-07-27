@extends('layouts.app')

@section('title', $localAgent->name ?? $localAgent->public_id)

@section('content')
<h1>{{ $localAgent->name ?? __('ui.local_agents.unregistered') }}</h1>

@if (session('generated_registration_token'))
<x-card style="border-color:#a41e1e;" :title="__('ui.local_agents.registration_once_title')">
    <p class="muted">{{ __('ui.local_agents.registration_once_intro', ['minutes' => config('deployment.agent_registration_token_ttl_minutes')]) }}</p>
    <label>registration_id</label>
    <div class="secret-box">{{ session('generated_registration_id') }}</div>
    <label>registration_token</label>
    <div class="secret-box">{{ session('generated_registration_token') }}</div>

    <label>{{ __('ui.local_agents.install_command_label') }}</label>
    <x-ltr>
        <div class="secret-box" style="white-space:pre-wrap;">.\install.ps1 -HubUrl {{ config('app.url') }} -RegistrationId {{ session('generated_registration_id') }} -RegistrationToken {{ session('generated_registration_token') }}</div>
    </x-ltr>
    <p class="muted">{{ __('ui.local_agents.install_command_hint') }}</p>
</x-card>
@endif

@if (session('generated_token'))
<x-card style="border-color:#a41e1e;" :title="__('ui.local_agents.registration_once_title')">
    <p class="muted">{{ __('ui.local_agents.credentials_once_intro') }}</p>
    <label>local_agent public_id</label>
    <div class="secret-box">{{ $localAgent->public_id }}</div>
    <label>agent_token</label>
    <div class="secret-box">{{ session('generated_token') }}</div>
    <label>signing_secret</label>
    <div class="secret-box">{{ session('generated_secret') }}</div>
</x-card>
@endif

<x-card>
    <table>
        <tr><th>public_id</th><td><x-ltr><code>{{ $localAgent->public_id }}</code></x-ltr></td></tr>
        <tr><th>{{ __('ui.common.status') }}</th><td>
            <x-badge :status="$localAgent->status" />
            &mdash;
            @if ($localAgent->isOnline())
                <x-badge status="online" />
            @else
                <x-badge status="offline" />
            @endif
        </td></tr>
        <tr><th>{{ __('ui.local_agents.os') }}</th><td>{{ $localAgent->operating_system ?? '—' }}</td></tr>
        <tr><th>{{ __('ui.local_agents.hostname') }}</th><td><x-ltr>{{ $localAgent->hostname ?? '—' }}</x-ltr></td></tr>
        <tr><th>{{ __('ui.local_agents.version') }}</th><td>
            <x-ltr>{{ $localAgent->agent_version ?? '—' }}</x-ltr>
            @if ($localAgent->isUpdateAvailable())
                <span class="badge badge-queued">{{ __('ui.local_agents.update_available', ['version' => config('deployment.latest_local_agent_version')]) }}</span>
            @endif
        </td></tr>
        <tr><th>{{ __('ui.local_agents.php_version') }}</th><td><x-ltr>{{ $localAgent->capabilities['php'] ?? '—' }}</x-ltr></td></tr>
        <tr><th>{{ __('ui.local_agents.capabilities') }}</th><td>
            @if ($localAgent->capabilities)
                <pre>{{ json_encode($localAgent->capabilities, JSON_PRETTY_PRINT) }}</pre>
            @else
                <span class="muted">{{ __('ui.servers.not_reported_yet') }}</span>
            @endif
        </td></tr>
        <tr><th>{{ __('ui.common.last_heartbeat') }}</th><td>{{ $localAgent->last_seen_at?->diffForHumans() ?? __('status.never') }}</td></tr>
        <tr><th>{{ __('ui.common.registered') }}</th><td>{{ $localAgent->created_at->diffForHumans() }}</td></tr>
        @if ($localAgent->approved_by)
        <tr><th>{{ __('ui.local_agents.approved_by') }}</th><td>{{ $localAgent->approved_by }} &mdash; {{ $localAgent->approved_at?->diffForHumans() }}</td></tr>
        @endif
    </table>

    <div style="margin-top:1rem;display:flex;gap:0.6rem;">
        @if ($localAgent->status === 'pending_approval')
            @can('local_agents.manage')
            <form method="POST" action="{{ route('local-agents.approve', $localAgent) }}">
                @csrf
                <button type="submit" class="btn btn-primary" @if(!$localAgent->name) disabled title="{{ __('ui.local_agents.approve_disabled_title') }}" @endif>{{ __('ui.local_agents.approve_button') }}</button>
            </form>
            <form method="POST" action="{{ route('local-agents.reject', $localAgent) }}" id="reject-local-agent-form" style="display:inline">
                @csrf
            </form>
            <button type="button" class="btn btn-danger" onclick="document.getElementById('reject-local-agent-dialog').showModal()">{{ __('ui.local_agents.reject_button') }}</button>
            <x-confirm-dialog
                id="reject-local-agent-dialog"
                form-id="reject-local-agent-form"
                :title="__('ui.local_agents.reject_button')"
                :body="__('ui.local_agents.reject_confirm_pending')"
                :confirm-label="__('ui.local_agents.reject_button')"
            />
            @endcan
        @elseif ($localAgent->status === 'approved')
            @can('local_agents.manage')
            <form method="POST" action="{{ route('local-agents.restart', $localAgent) }}" id="restart-local-agent-form" style="display:inline">
                @csrf
            </form>
            <button type="button" class="btn" onclick="document.getElementById('restart-local-agent-dialog').showModal()">{{ __('ui.local_agents.restart_button') }}</button>
            <x-confirm-dialog
                id="restart-local-agent-dialog"
                form-id="restart-local-agent-form"
                :title="__('ui.local_agents.restart_button')"
                :body="__('ui.local_agents.restart_confirm')"
                :confirm-label="__('ui.local_agents.restart_button')"
                :danger="false"
            />

            <form method="POST" action="{{ route('local-agents.fetch-logs', $localAgent) }}" style="display:inline">
                @csrf
                <button type="submit" class="btn">{{ __('ui.local_agents.fetch_logs_button') }}</button>
            </form>

            <form method="POST" action="{{ route('local-agents.rotate-token', $localAgent) }}" id="rotate-token-local-agent-form" style="display:inline">
                @csrf
            </form>
            <button type="button" class="btn" onclick="document.getElementById('rotate-token-local-agent-dialog').showModal()">{{ __('ui.local_agents.rotate_token_button') }}</button>
            <x-confirm-dialog
                id="rotate-token-local-agent-dialog"
                form-id="rotate-token-local-agent-form"
                :title="__('ui.local_agents.rotate_token_button')"
                :body="__('ui.local_agents.rotate_token_confirm')"
                :confirm-label="__('ui.local_agents.rotate_token_button')"
            />

            <form method="POST" action="{{ route('local-agents.disable', $localAgent) }}" id="disable-local-agent-form" style="display:inline">
                @csrf
            </form>
            <button type="button" class="btn btn-danger" onclick="document.getElementById('disable-local-agent-dialog').showModal()">{{ __('ui.local_agents.disable_button') }}</button>
            <x-confirm-dialog
                id="disable-local-agent-dialog"
                form-id="disable-local-agent-form"
                :title="__('ui.local_agents.disable_button')"
                :body="__('ui.local_agents.disable_confirm')"
                :confirm-label="__('ui.local_agents.disable_button')"
            />
            @endcan
        @elseif ($localAgent->status === 'disabled')
            @can('local_agents.manage')
            <form method="POST" action="{{ route('local-agents.reenable', $localAgent) }}">
                @csrf
                <button type="submit" class="btn">{{ __('ui.local_agents.reenable_button') }}</button>
            </form>
            @endcan
        @endif
    </div>
</x-card>

<x-card :title="__('ui.local_agents.projects_title')">
    <table>
        <thead><tr>
            <th>{{ __('ui.common.project') }}</th>
            <th>{{ __('ui.local_agents.mapping_local_path') }}</th>
            <th>{{ __('ui.local_agents.mapping_branch') }}</th>
            <th>{{ __('ui.local_agents.mapping_remote') }}</th>
            <th>{{ __('ui.publish.title') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($localAgent->projectMappings as $mapping)
            <tr>
                <td><a href="{{ route('projects.show', $mapping->project) }}">{{ $mapping->project->name }}</a></td>
                <td><x-ltr><code>{{ $mapping->local_path }}</code></x-ltr></td>
                <td>{{ $mapping->git_branch ?? $mapping->project->branch }}</td>
                <td>{{ $mapping->remote_name }}</td>
                <td>
                    @can('local_agents.manage')
                    <form method="POST" action="{{ route('local-agents.mappings.deployment-mode', [$localAgent, $mapping]) }}" style="display:flex;gap:0.4rem;align-items:center;">
                        @csrf
                        <select name="deployment_mode" onchange="this.form.submit()" title="{{ __('ui.deployment_mode.' . $mapping->deployment_mode . '_hint', ['minutes' => config('deployment.auto_publish_stability_minutes')]) }}">
                            @foreach (['manual', 'approval', 'auto'] as $mode)
                                <option value="{{ $mode }}" @selected($mapping->deployment_mode === $mode)>{{ __('ui.deployment_mode.' . $mode) }}</option>
                            @endforeach
                        </select>
                        <noscript><button type="submit" class="btn btn-small">{{ __('ui.deployment_mode.update_button') }}</button></noscript>
                    </form>
                    @else
                    {{ __('ui.deployment_mode.' . $mapping->deployment_mode) }}
                    @endcan
                </td>
                <td>
                    @can('local_agents.manage')
                    <form method="POST" action="{{ route('local-agents.mappings.destroy', [$localAgent, $mapping]) }}" id="remove-mapping-{{ $mapping->id }}-form" style="display:inline">
                        @csrf
                        @method('DELETE')
                    </form>
                    <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('remove-mapping-{{ $mapping->id }}-dialog').showModal()">{{ __('ui.local_agents.remove_mapping_button') }}</button>
                    <x-confirm-dialog
                        id="remove-mapping-{{ $mapping->id }}-dialog"
                        form-id="remove-mapping-{{ $mapping->id }}-form"
                        :title="__('ui.local_agents.remove_mapping_button')"
                        :body="__('ui.local_agents.remove_mapping_confirm')"
                        :confirm-label="__('ui.local_agents.remove_mapping_button')"
                    />
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="6"><x-empty-state>{{ __('ui.local_agents.no_mappings') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>

    @can('local_agents.manage')
    @if ($availableProjects->isNotEmpty())
    <form method="POST" action="{{ route('local-agents.mappings.store', $localAgent) }}" style="margin-top:1rem;display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap;">
        @csrf
        <div>
            <label>{{ __('ui.common.project') }}</label>
            <select name="project_id" required>
                @foreach ($availableProjects as $project)
                    <option value="{{ $project->id }}">{{ $project->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>{{ __('ui.local_agents.mapping_local_path') }}</label>
            <input type="text" name="local_path" required placeholder="C:\laragon\www\...">
        </div>
        <div>
            <label>{{ __('ui.local_agents.mapping_branch') }}</label>
            <input type="text" name="git_branch" placeholder="main">
        </div>
        <div>
            <label>{{ __('ui.local_agents.mapping_remote') }}</label>
            <input type="text" name="remote_name" placeholder="origin">
        </div>
        <button type="submit" class="btn btn-primary">{{ __('ui.local_agents.add_mapping_button') }}</button>
    </form>
    @endif
    @endcan
</x-card>

<x-card :title="__('ui.local_agents.recent_publishes_title')">
    <table>
        <thead><tr>
            <th>{{ __('ui.common.project') }}</th>
            <th>{{ __('ui.common.action') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.common.created') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($localAgent->publishJobs as $job)
            <tr>
                <td><a href="{{ route('projects.publish.show', [$job->project, $job]) }}">{{ $job->project->name }}</a></td>
                <td>{{ __('ui.publish.action_' . $job->action) }}</td>
                <td><x-badge :status="$job->status" /></td>
                <td>{{ $job->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="4"><x-empty-state>{{ __('ui.local_agents.no_publish_jobs') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

@if ($latestLogFetch)
<x-card :title="__('ui.local_agents.recent_log_output_title')">
    <p class="muted">{{ $latestLogFetch->updated_at->diffForHumans() }}</p>
    <pre>{{ $latestLogFetch->output }}</pre>
</x-card>
@endif
@endsection
