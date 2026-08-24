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
        <tr><th>Agent role</th><td><strong>{{ $localAgent->role }}</strong></td></tr>
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
        @if ($latestUpgrade)
        <tr><th>Latest Linux upgrade</th><td>
            <x-badge :status="$latestUpgrade->status" />
            <x-ltr><code>{{ $latestUpgrade->reported_version ?? $localAgent->agent_version ?? '-' }}</code> &rarr; <code>{{ $latestUpgrade->expected_version ?? '-' }}</code></x-ltr>
            @if ($latestUpgrade->error_message)
                <span class="error">{{ $latestUpgrade->error_message }}</span>
            @endif
        </td></tr>
        @endif
        <tr><th>{{ __('ui.common.registered') }}</th><td>{{ $localAgent->created_at->diffForHumans() }}</td></tr>
        @if ($localAgent->approved_by)
        <tr><th>{{ __('ui.local_agents.approved_by') }}</th><td>{{ $localAgent->approved_by }} &mdash; {{ $localAgent->approved_at?->diffForHumans() }}</td></tr>
        @endif
    </table>

    @can('local_agents.manage')
    <form method="POST" action="{{ route('local-agents.role', $localAgent) }}" style="display:flex;gap:0.6rem;align-items:flex-end;margin-top:1rem;">
        @csrf
        <div>
            <label for="agent-role">Agent role</label>
            <select id="agent-role" name="role">
                @foreach (\App\Models\LocalAgent::ROLES as $role)
                    <option value="{{ $role }}" {{ $localAgent->role === $role ? 'selected' : '' }}>{{ $role }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn">Save role</button>
    </form>
    @endcan

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

            <form method="POST" action="{{ route('local-agents.reload-config', $localAgent) }}" style="display:inline" title="{{ __('ui.local_agents.reload_config_hint') }}">
                @csrf
                <button type="submit" class="btn">{{ __('ui.local_agents.reload_config_button') }}</button>
            </form>

            @if (strcasecmp((string) $localAgent->operating_system, 'Linux') === 0 && $localAgent->isUpdateAvailable())
                @if (($localAgent->capabilities['linux_self_upgrade'] ?? false) === true)
                <form method="POST" action="{{ route('local-agents.upgrade', $localAgent) }}" style="display:inline">
                    @csrf
                    <button type="submit" class="btn btn-primary">Upgrade to {{ config('deployment.latest_local_agent_version') }}</button>
                </form>
                @else
                <span class="muted">Bootstrap once with the official <code>deployment-local-agent/upgrade-linux.sh</code>; future upgrades can be issued here.</span>
                @endif
            @endif

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
            <th>Source Workspace</th>
            <th>Production Target</th>
            <th>{{ __('ui.local_agents.mapping_branch') }}</th>
            <th>{{ __('ui.local_agents.mapping_remote') }}</th>
            <th>{{ __('ui.publish.title') }}</th>
            <th>Artifact / Commit / Version</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($localAgent->projectMappings as $mapping)
            <tr>
                <td><a href="{{ route('projects.show', $mapping->project) }}">{{ $mapping->project->name }}</a></td>
                @can('local_agents.manage')
                <td colspan="4">
                    <form method="POST" action="{{ route('local-agents.mappings.update', [$localAgent, $mapping]) }}" style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;">
                        @csrf
                        @method('PUT')
                        <x-ltr><input type="text" name="source_workspace_path" value="{{ $mapping->sourceWorkspacePath() }}" required placeholder="/opt/deployment-agent/workspaces/project" style="min-width:18rem;" title="Source Workspace"></x-ltr>
                        <x-ltr><input type="text" name="production_path" value="{{ $mapping->production_path }}" placeholder="/var/www/project" style="min-width:14rem;" title="Production Target (display/reference only)"></x-ltr>
                        <input type="text" name="git_branch" value="{{ $mapping->git_branch }}" placeholder="{{ $mapping->project->branch }}" style="max-width:8rem;">
                        <input type="text" name="remote_name" value="{{ $mapping->remote_name }}" placeholder="origin" style="max-width:8rem;">
                        <button type="submit" class="btn btn-small">{{ __('ui.local_agents.mapping_update_button') }}</button>
                    </form>
                </td>
                @else
                <td><x-ltr><code>{{ $mapping->sourceWorkspacePath() }}</code></x-ltr></td>
                <td><x-ltr><code>{{ $mapping->production_path ?? '—' }}</code></x-ltr></td>
                <td>{{ $mapping->git_branch ?? $mapping->project->branch }}</td>
                <td>{{ $mapping->remote_name }}</td>
                @endcan
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
                    @php($artifact = $mapping->project->artifacts->first())
                    <x-badge :status="$artifact?->status ?? 'missing'" />
                    <x-ltr><code>{{ $artifact ? substr($artifact->commit_hash, 0, 10) : '—' }}</code></x-ltr>
                    <span class="muted">Agent {{ $localAgent->agent_version ?? '—' }}</span>
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
            <tr><td colspan="8"><x-empty-state>{{ __('ui.local_agents.no_mappings') }}</x-empty-state></td></tr>
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
            <label>Source Workspace</label>
            <input type="text" name="source_workspace_path" required placeholder="/opt/deployment-agent/workspaces/project">
        </div>
        <div>
            <label>Production Target</label>
            <input type="text" name="production_path" placeholder="/var/www/project">
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

    @if ($availableProjects->count() > 1)
    <details style="margin-top:1rem;">
        <summary>{{ __('ui.local_agents.bulk_add_summary', ['count' => $availableProjects->count()]) }}</summary>
        <p class="muted">{{ __('ui.local_agents.bulk_add_hint') }}</p>
        <form method="POST" action="{{ route('local-agents.mappings.bulk-store', $localAgent) }}">
            @csrf
            <table>
                <thead><tr>
                    <th></th>
                    <th>{{ __('ui.common.project') }}</th>
                    <th>Source Workspace</th>
                    <th>Production Target</th>
                    <th>{{ __('ui.local_agents.mapping_branch') }}</th>
                    <th>{{ __('ui.local_agents.mapping_remote') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($availableProjects as $project)
                    <tr>
                        <td><input type="checkbox" name="enabled[{{ $project->id }}]" value="1"></td>
                        <td>{{ $project->name }}</td>
                        <td><x-ltr><input type="text" name="source_workspace_path[{{ $project->id }}]" value="/opt/deployment-agent/workspaces/{{ $project->project_key }}"></x-ltr></td>
                        <td><x-ltr><input type="text" name="production_path[{{ $project->id }}]" value="/var/www/{{ $project->project_key }}"></x-ltr></td>
                        <td><input type="text" name="git_branch[{{ $project->id }}]" placeholder="{{ $project->branch }}"></td>
                        <td><input type="text" name="remote_name[{{ $project->id }}]" value="origin"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary" style="margin-top:0.6rem;">{{ __('ui.local_agents.bulk_add_button') }}</button>
        </form>
    </details>
    @endif
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
                <td><x-badge :status="$job->isLeaseExpired() ? 'awaiting_recovery' : $job->status" /></td>
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
