@extends('layouts.app')

@section('title', $project->name)

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ $project->name }}</h1>
    <div>
        @can('projects.update')
        <a href="{{ route('projects.edit', $project) }}" class="btn">{{ __('ui.common.edit') }}</a>
        @endcan
        @can('projects.delete')
        <form method="POST" action="{{ route('projects.destroy', $project) }}" id="delete-project-form" style="display:inline">
            @csrf
            @method('DELETE')
        </form>
        <button type="button" class="btn btn-danger" onclick="document.getElementById('delete-project-dialog').showModal()">{{ __('ui.projects.delete_button') }}</button>
        <x-confirm-dialog
            id="delete-project-dialog"
            form-id="delete-project-form"
            :title="__('ui.projects.delete_button')"
            :body="__('ui.projects.delete_confirm')"
            :confirm-label="__('ui.projects.delete_button')"
        />
        @endcan
    </div>
</div>
@if ($project->description)
<p class="muted" style="margin-top:-0.6rem;">{{ $project->description }}</p>
@endif

<x-card>
    <table>
        <tr><th>{{ __('ui.common.repository') }}</th><td><x-ltr>{{ $project->repository }}</x-ltr></td></tr>
        <tr><th>{{ __('ui.common.branch') }}</th><td>{{ $project->branch }}</td></tr>
        <tr><th>{{ __('ui.common.environment') }}</th><td>{{ $project->environment }}</td></tr>
        <tr><th>{{ __('ui.common.server') }}</th><td><a href="{{ route('servers.show', $project->server) }}">{{ $project->server->name }}</a></td></tr>
        <tr><th>{{ __('ui.common.auto_deploy') }}</th><td>{{ $project->auto_deploy ? __('ui.projects.auto_deploy_yes') : __('ui.projects.auto_deploy_no_long') }}</td></tr>
        <tr><th>Artifact policy</th><td>{{ $project->requires_complete_artifact ? 'Complete artifact required' : 'Legacy fallback allowed' }}</td></tr>
        <tr><th>project_key</th><td><x-ltr><code>{{ $project->project_key }}</code></x-ltr> <span class="muted">({{ __('ui.projects.project_key_must_match') }})</span></td></tr>
    </table>
</x-card>

<x-card title="Transfer trace">
    <table>
        <tr><th>State</th><td>{{ $transferTrace['state'] }}</td></tr>
        <tr><th>Local SHA</th><td><x-ltr><code>{{ $transferTrace['local_head_sha'] ?? 'not reported' }}</code></x-ltr></td></tr>
        <tr><th>Remote SHA</th><td><x-ltr><code>{{ $transferTrace['remote_tracking_sha'] ?? 'not reported' }}</code></x-ltr></td></tr>
        <tr><th>Pushed SHA</th><td><x-ltr><code>{{ $transferTrace['pushed_sha'] ?? 'unknown' }}</code></x-ltr></td></tr>
        <tr><th>Artifact</th><td><x-ltr><code>{{ $transferTrace['artifact_sha'] ?? 'none' }}</code></x-ltr> — {{ $transferTrace['artifact_status'] ?? 'none' }}</td></tr>
        <tr><th>Deployment</th><td><x-ltr><code>{{ $transferTrace['deployment_sha'] ?? 'none' }}</code></x-ltr> — {{ $transferTrace['deployment_status'] ?? 'none' }}</td></tr>
        <tr><th>Production SHA</th><td><x-ltr><code>{{ $transferTrace['production_sha'] ?? 'unknown' }}</code></x-ltr></td></tr>
        <tr><th>Pending SHA</th><td><x-ltr><code>{{ $transferTrace['pending_transfer_sha'] ?? 'none' }}</code></x-ltr></td></tr>
        <tr><th>Failure stage</th><td>{{ $transferTrace['failure_stage'] ?? 'none' }} @if($transferTrace['deployment_failure_code']) — {{ $transferTrace['deployment_failure_code'] }} @endif</td></tr>
    </table>
    @if ($transferTrace['warnings'] !== [])
        <p class="muted">{{ implode(' · ', $transferTrace['warnings']) }}</p>
    @endif
</x-card>

@can('projects.manage-members')
<x-card :title="__('ui.projects.members_title')">
    <p class="muted">{{ __('ui.projects.members_intro') }}</p>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }}</th>
            <th>{{ __('ui.common.email') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($project->members as $member)
            <tr>
                <td>{{ $member->user->name }}</td>
                <td><x-ltr>{{ $member->user->email }}</x-ltr></td>
                <td>
                    <form method="POST" action="{{ route('projects.members.destroy', [$project, $member]) }}" id="remove-member-{{ $member->id }}-form" style="display:inline">
                        @csrf
                        @method('DELETE')
                    </form>
                    <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('remove-member-{{ $member->id }}-dialog').showModal()">{{ __('ui.projects.remove_member_button') }}</button>
                    <x-confirm-dialog
                        id="remove-member-{{ $member->id }}-dialog"
                        form-id="remove-member-{{ $member->id }}-form"
                        :title="__('ui.projects.remove_member_button')"
                        :body="__('ui.projects.remove_member_confirm')"
                        :confirm-label="__('ui.projects.remove_member_button')"
                    />
                </td>
            </tr>
        @empty
            <tr><td colspan="3"><x-empty-state>{{ __('ui.projects.no_members') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>

    <details class="inline-form">
        <summary>{{ __('ui.projects.add_member_button') }}</summary>
        <form method="POST" action="{{ route('projects.members.store', $project) }}" style="margin-top:0.8rem;max-width:420px;">
            @csrf
            <label for="user_id">{{ __('ui.projects.add_member_label') }}</label>
            <select id="user_id" name="user_id" required>
                @foreach ($availableUsers as $availableUser)
                    <option value="{{ $availableUser->id }}">{{ $availableUser->name }} ({{ $availableUser->email }})</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary" style="margin-top:0.6rem;">{{ __('ui.projects.add_member_button') }}</button>
        </form>
    </details>
</x-card>
@endcan

{{-- Phase 4-C.5: Environments + Deployment Targets --}}
<x-card :title="__('ui.environments.section_title')">
    @forelse ($project->environments as $environment)
        <div class="environment-block">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <strong>{{ $environment->name }}</strong>
                    <x-badge :status="$environment->status" />
                    <span class="muted">— {{ __('ui.environments.targets_count', ['count' => $environment->deploymentTargets->count()]) }}</span>
                </div>
                @can('environments.manage')
                <form method="POST" action="{{ route('environments.toggle-status', $environment) }}">
                    @csrf
                    <button type="submit" class="btn btn-small">
                        {{ $environment->status === 'active' ? __('ui.environments.disable_button') : __('ui.environments.enable_button') }}
                    </button>
                </form>
                @endcan
            </div>

            @forelse ($environment->deploymentTargets as $target)
                <div class="target-row">
                    <div>
                        <strong>{{ $target->name }}</strong>
                        <x-badge status="{{ $target->is_active ? 'active' : 'disabled' }}" />
                        <span class="muted">
                            {{ __('ui.common.server') }}: <a href="{{ route('servers.show', $target->server) }}">{{ $target->server->name }}</a>
                            —
                            {{ __('ui.targets.agent_label') }}:
                            @if ($target->agent)
                                {{ $target->agent->name ?? $target->agent->public_id }}
                                @if ($target->agent->isOnline())
                                    <x-badge status="online" />
                                @else
                                    <x-badge status="offline" />
                                @endif
                            @else
                                {{ __('ui.targets.no_agent') }}
                            @endif
                        </span>
                    </div>
                    <div class="target-actions">
                        @can('environments.manage')
                        <form method="POST" action="{{ route('deployment-targets.toggle-status', $target) }}">
                            @csrf
                            <button type="submit" class="btn btn-small">
                                {{ $target->is_active ? __('ui.targets.disable_button') : __('ui.targets.enable_button') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('deployment-targets.destroy', $target) }}" id="delete-target-{{ $target->id }}-form" style="display:inline">
                            @csrf
                            @method('DELETE')
                        </form>
                        <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('delete-target-{{ $target->id }}-dialog').showModal()">{{ __('ui.targets.delete_button') }}</button>
                        <x-confirm-dialog
                            id="delete-target-{{ $target->id }}-dialog"
                            form-id="delete-target-{{ $target->id }}-form"
                            :title="__('ui.targets.delete_button')"
                            :body="__('ui.targets.delete_confirm')"
                            :confirm-label="__('ui.targets.delete_button')"
                        />
                        @endcan
                    </div>
                </div>
            @empty
                <p class="muted" style="margin-top:0.6rem;"><x-empty-state>{{ __('ui.targets.empty_state') }}</x-empty-state></p>
            @endforelse

            @can('environments.manage')
            <details class="inline-form">
                <summary>{{ __('ui.targets.add_button') }}</summary>
                <form method="POST" action="{{ route('deployment-targets.store', $environment) }}" style="margin-top:0.8rem;">
                    @csrf
                    <label>{{ __('ui.targets.select_servers_label') }}</label>
                    @if ($availableServers->isEmpty())
                        <p class="muted">{{ __('ui.targets.no_servers_available') }}</p>
                    @else
                        <div class="server-pick-list">
                        @foreach ($availableServers as $server)
                            <label>
                                <input type="checkbox" name="server_ids[]" value="{{ $server->id }}">
                                {{ $server->name }}
                                —
                                {{ __('ui.targets.agent_label') }}:
                                @if ($server->agents->isNotEmpty() && $server->agents->first()->isOnline())
                                    <x-badge status="online" />
                                @elseif ($server->agents->isNotEmpty())
                                    <x-badge status="offline" />
                                @else
                                    <span class="muted">{{ __('ui.targets.no_agent') }}</span>
                                @endif
                            </label>
                        @endforeach
                        </div>
                        <button type="submit" class="btn btn-primary btn-small" style="margin-top:0.6rem;">{{ __('ui.targets.submit_button') }}</button>
                    @endif
                </form>
            </details>
            @endcan
        </div>
    @empty
        <x-empty-state>{{ __('ui.environments.empty_state') }}</x-empty-state>
    @endforelse

    @can('environments.manage')
    <details class="inline-form">
        <summary>{{ __('ui.environments.create_button') }}</summary>
        <form method="POST" action="{{ route('environments.store', $project) }}" style="margin-top:0.8rem;max-width:420px;">
            @csrf
            <label for="environment_name">{{ __('ui.common.name') }}</label>
            <input type="text" id="environment_name" name="name" placeholder="Production" required>

            <label for="environment_key">{{ __('ui.environments.key_label') }}</label>
            <input type="text" id="environment_key" name="key" placeholder="production" pattern="[a-z0-9-]+" dir="ltr" style="text-align:left;" required>
            <p class="muted">{{ __('ui.environments.key_help') }}</p>

            <button type="submit" class="btn btn-primary" style="margin-top:0.6rem;">{{ __('ui.environments.create_submit') }}</button>
        </form>
    </details>
    @endcan
</x-card>

@if (session('generated_webhook_secret'))
<x-card style="border-color:#a41e1e;" :title="__('ui.projects.secret_once_title')">
    <p class="muted">{{ __('ui.projects.secret_once_intro') }}</p>
    <div class="secret-box">{{ session('generated_webhook_secret') }}</div>
</x-card>
@endif

<x-card :title="__('ui.projects.webhook_title')">
    @if (session('webhook_auto_configured'))
        <x-alert type="success">{{ __('ui.projects.webhook_auto_configured') }}</x-alert>
    @endif

    {{-- Phase 4-L Task 2: current connection status, plus a one-click way
         to connect it when it isn't — never asks again for repository,
         branch, or a new secret, since all three already exist. --}}
    @if ($project->hasGithubWebhookConfigured())
        <x-alert type="success" style="margin-bottom:0.8rem;">{{ __('ui.projects.github_connected_notice', ['login' => $project->githubAccount->account_login]) }}</x-alert>
    @elseif ($connectableGithubAccounts->isNotEmpty())
        @can('projects.update')
        <form method="POST" action="{{ route('projects.connect-github', $project) }}" style="display:flex;gap:0.5rem;align-items:center;margin-bottom:0.8rem;flex-wrap:wrap;">
            @csrf
            @if ($connectableGithubAccounts->count() > 1)
                <select name="github_account_id" style="width:auto;">
                    @foreach ($connectableGithubAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->account_login }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="github_account_id" value="{{ $connectableGithubAccounts->first()->id }}">
            @endif
            <button type="submit" class="btn btn-primary btn-small">{{ __('ui.github.connect_project_button') }}</button>
        </form>
        @endcan
    @endif

    <p class="muted">{{ __('ui.projects.webhook_intro') }}</p>
    <label>{{ __('ui.projects.payload_url') }}</label>
    <div class="secret-box">{{ $webhookUrl }}</div>
    <label>{{ __('ui.projects.secret_label') }} @if(!session('generated_webhook_secret')) <span class="muted">({{ __('ui.projects.secret_masked_hint') }})</span> @endif</label>
    <div class="secret-box">{{ $maskedWebhookSecret }}</div>
    <p class="muted">{{ __('ui.projects.webhook_content_type') }}</p>

    @can('projects.update')
    <form method="POST" action="{{ route('projects.rotate-webhook-secret', $project) }}" id="rotate-webhook-secret-form" style="display:inline">
        @csrf
    </form>
    <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('rotate-webhook-secret-dialog').showModal()">{{ __('ui.projects.rotate_webhook_button') }}</button>
    <x-confirm-dialog
        id="rotate-webhook-secret-dialog"
        form-id="rotate-webhook-secret-form"
        :title="__('ui.projects.rotate_webhook_button')"
        :body="__('ui.projects.rotate_webhook_confirm')"
        :confirm-label="__('ui.projects.rotate_webhook_button')"
    />
    @endcan
</x-card>

<x-card :title="__('ui.projects.pipeline_title')">
    <pre>{{ json_encode($project->pipeline, JSON_PRETTY_PRINT) }}</pre>
</x-card>

@can('deploy', $project)
<x-card :title="__('ui.projects.manual_deploy_title')">
    <form method="POST" action="{{ route('projects.deploy', $project) }}">
        @csrf
        <input type="text" name="commit_hash" placeholder="{{ __('ui.projects.commit_sha_placeholder') }}" dir="ltr" style="text-align:left;" required>

        {{-- Phase 4-I Task 7: entirely optional — a plain commit-only POST
             (every pre-4-I caller, including every existing test) still
             works exactly as before, since both of these are nullable. --}}
        <label for="release_label">{{ __('ui.deployments.release_label_field') }}</label>
        <input type="text" id="release_label" name="release_label" placeholder="{{ __('ui.deployments.release_label_placeholder') }}" dir="ltr" style="text-align:left;" maxlength="100">

        <label for="release_notes">{{ __('ui.deployments.release_notes_field') }}</label>
        <textarea id="release_notes" name="release_notes" rows="2" placeholder="{{ __('ui.deployments.release_notes_placeholder') }}" maxlength="2000"></textarea>

        <button type="submit" class="btn btn-primary" style="margin-top:0.6rem;">{{ __('ui.projects.queue_deploy_button') }}</button>
    </form>
</x-card>
@endcan

@can('local_agents.publish')
<x-card :title="__('ui.publish.button')">
    @if ($openChangeDetection)
        <p>
            {{ __('ui.local_agents.watching_status', ['time' => $openChangeDetection->detected_at->diffForHumans(), 'count' => $openChangeDetection->files_changed_count]) }}
        </p>
        <a href="{{ route('projects.changes.show', [$project, $openChangeDetection]) }}" class="btn btn-primary btn-small">{{ __('ui.local_agents.view_changes_button') }}</a>
    @endif

    @if ($project->localProjectMappings->isEmpty())
        <p class="muted" style="margin-top:1rem;">{{ __('ui.publish.no_mapping') }}</p>
    @else
        <ul style="margin:0.6rem 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:0.2rem;">
            @foreach ($project->localProjectMappings as $mapping)
                <li>
                    <a href="{{ route('local-agents.show', $mapping->localAgent) }}">{{ $mapping->localAgent->name ?? $mapping->localAgent->public_id }}</a>
                    @if ($mapping->localAgent->isOnline())
                        <x-badge status="online" />
                    @else
                        <x-badge status="offline" />
                        <span class="muted">{{ __('ui.publish.agent_last_heartbeat', ['time' => $mapping->localAgent->last_seen_at?->diffForHumans() ?? __('status.never')]) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($project->localProjectMappings->every(fn ($mapping) => !$mapping->localAgent->isOnline()))
            <p class="muted" style="margin-top:0.4rem;">{{ __('ui.publish.agent_offline_warning') }}</p>
        @endif

        <form method="POST" action="{{ route('projects.publish', $project) }}" style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap;margin-top:0.6rem;">
            @csrf
            @if ($project->localProjectMappings->count() > 1)
                <div>
                    <label>{{ __('ui.publish.select_local_agent') }}</label>
                    <select name="local_project_mapping_id" required>
                        @foreach ($project->localProjectMappings as $mapping)
                            <option value="{{ $mapping->id }}">{{ $mapping->localAgent->name ?? $mapping->localAgent->public_id }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <input type="hidden" name="local_project_mapping_id" value="{{ $project->localProjectMappings->first()->id }}">
            @endif
            <button type="submit" class="btn btn-primary">{{ __('ui.publish.button') }}</button>
        </form>

        <form method="POST" action="{{ route('projects.publish.rollback', $project) }}" id="rollback-publish-form" style="display:inline;margin-top:0.6rem;">
            @csrf
            @if ($project->localProjectMappings->count() === 1)
                <input type="hidden" name="local_project_mapping_id" value="{{ $project->localProjectMappings->first()->id }}">
            @endif
        </form>
        <button type="button" class="btn btn-danger btn-small" style="margin-top:0.6rem;" onclick="document.getElementById('rollback-publish-dialog').showModal()">{{ __('ui.publish.rollback_button') }}</button>
        <x-confirm-dialog
            id="rollback-publish-dialog"
            form-id="rollback-publish-form"
            :title="__('ui.publish.rollback_button')"
            :body="__('ui.publish.rollback_confirm')"
            :confirm-label="__('ui.publish.rollback_button')"
        />
    @endif
</x-card>
@endcan

<x-card :title="__('ui.projects.history_title')">
    <table>
        <thead><tr>
            <th>{{ __('ui.deployments.release_label') }}</th>
            <th>{{ __('ui.common.commit') }}</th>
            <th>{{ __('ui.common.action') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.projects.history_user') }}</th>
            <th>{{ __('ui.projects.history_duration') }}</th>
            <th>{{ __('ui.common.created') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($deployments as $deployment)
            <tr>
                <td><span class="release-tag">{{ $deployment->displayLabel() }}</span></td>
                <td><x-ltr><code>{{ substr($deployment->commit_hash, 0, 10) }}</code></x-ltr></td>
                <td>{{ $deployment->action }}</td>
                <td><x-badge :status="$deployment->status" /></td>
                <td>{{ $deployment->triggered_by ?? $deployment->trigger }}</td>
                <td>@if($deployment->durationSeconds() !== null)<x-ltr>{{ gmdate('H:i:s', $deployment->durationSeconds()) }}</x-ltr>@else — @endif</td>
                <td>{{ $deployment->created_at->diffForHumans() }}</td>
                <td><a href="{{ route('deployments.show', $deployment) }}" class="btn btn-small">{{ __('ui.common.view') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="8"><x-empty-state>{{ __('ui.projects.no_deployments') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
