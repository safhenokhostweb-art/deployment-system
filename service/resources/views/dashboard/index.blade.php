@extends('layouts.app')

@section('title', __('dashboard_ui.title'))

@section('content')
@php
    $visibleAgentCount = $agents->count();
    $onlineAgents = $agents->filter(fn ($agent) => $agent->status === \App\Models\Agent::STATUS_APPROVED && $agent->isOnline());
    $offlineAgents = $agents->filter(fn ($agent) => $agent->status === \App\Models\Agent::STATUS_APPROVED && ! $agent->isOnline());
    $minimumArtifactAgentVersion = (string) config('deployment.min_server_agent_version_for_artifact');
    $outdatedAgents = $agents->filter(fn ($agent) => $agent->status === \App\Models\Agent::STATUS_APPROVED
        && (! $agent->agent_version || version_compare($agent->agent_version, $minimumArtifactAgentVersion, '<')));

    $visibleToday = $recentDeployments->filter(fn ($deployment) => $deployment->created_at->isToday());
    $recentDurations = $recentDeployments
        ->map(fn ($deployment) => $deployment->durationSeconds())
        ->filter(fn ($seconds) => $seconds !== null);
    $averageRecentDuration = $recentDurations->isNotEmpty()
        ? gmdate('H:i:s', (int) round($recentDurations->average()))
        : null;

    $recentHealthFailures = $health['recentErrors']->filter(
        fn ($deployment) => $deployment->failure_code === \App\Models\Deployment::FAILURE_CODE_HEALTH_CHECK_FAILED
    );
    $recentArtifactLinked = $recentDeployments->whereNotNull('artifact_id');
    $recentArtifactMissing = $recentDeployments->filter(
        fn ($deployment) => $deployment->project->requires_complete_artifact && ! $deployment->artifact_id
    );

    $attentionCount = $health['problemAgents']->count()
        + $health['problemDeployments']->count()
        + $recentArtifactMissing->count();
    $hasCriticalSignal = $health['problemDeployments']->isNotEmpty()
        || $recentHealthFailures->isNotEmpty()
        || $offlineAgents->isNotEmpty()
        || $recentArtifactMissing->isNotEmpty();
    $platformSignal = $hasCriticalSignal ? 'failed' : ($attentionCount > 0 ? 'pending' : 'verified');
@endphp

<x-ui.page-header
    :eyebrow="__('dashboard_ui.eyebrow')"
    :title="__('dashboard_ui.title')"
    :description="__('dashboard_ui.description')"
>
    <x-slot:actions>
        @can('projects.create')
            <a href="{{ route('projects.create') }}" class="btn btn-primary">{{ __('ui.dashboard.quick_action_new_project') }}</a>
        @endcan
        @can('servers.create')
            <a href="{{ route('servers.create') }}" class="btn">{{ __('ui.dashboard.quick_action_add_server') }}</a>
        @endcan
        @can('agents.approve')
            <a href="{{ route('agents.pending') }}" class="btn">{{ __('ui.dashboard.quick_action_view_pending_agents') }}</a>
        @endcan
        @can('deployments.view')
            <a href="#recent-deployments" class="btn">{{ __('ui.dashboard.quick_action_view_deployments') }}</a>
        @endcan
    </x-slot:actions>
</x-ui.page-header>

<section class="ui-platform-verdict ui-platform-verdict--{{ $hasCriticalSignal ? 'danger' : ($attentionCount > 0 ? 'warning' : 'success') }}" aria-labelledby="platform-health-title">
    <div>
        <x-ui.health-indicator
            :status="$platformSignal"
            :label="$attentionCount > 0 ? __('dashboard_ui.health_attention') : __('dashboard_ui.health_clear')"
            :detail="__('dashboard_ui.health_scope_note')"
        />
        <h2 id="platform-health-title">{{ $attentionCount > 0 ? __('dashboard_ui.verdict_attention', ['count' => $attentionCount]) : __('dashboard_ui.verdict_clear') }}</h2>
        <p>{{ $attentionCount > 0 ? __('dashboard_ui.verdict_attention_description') : __('dashboard_ui.verdict_clear_description') }}</p>
    </div>
    <div class="ui-platform-verdict__actions">
        <a href="#attention-required" class="btn">{{ __('dashboard_ui.review_attention') }}</a>
    </div>
</section>

<x-ui.info-card
    :title="__('dashboard_ui.system_health_title')"
    :description="__('dashboard_ui.system_health_description')"
    class="ui-dashboard-section"
>
    <div class="ui-signal-grid">
        <div class="ui-signal">
            <span class="ui-signal__label">{{ __('dashboard_ui.hub_status') }}</span>
            <x-ui.health-indicator status="not_configured" :label="__('dashboard_ui.not_exposed')" :detail="__('dashboard_ui.hub_status_note')" />
        </div>
        <div class="ui-signal">
            <span class="ui-signal__label">{{ __('dashboard_ui.agent_availability') }}</span>
            <x-ui.health-indicator
                :status="$offlineAgents->isEmpty() ? 'verified' : 'failed'"
                :label="__('dashboard_ui.online_of_visible', ['online' => $onlineAgents->count(), 'visible' => $visibleAgentCount])"
                :detail="__('dashboard_ui.visible_agent_scope')"
            />
        </div>
        <div class="ui-signal">
            <span class="ui-signal__label">{{ __('dashboard_ui.queue_status') }}</span>
            <x-ui.health-indicator status="not_configured" :label="__('dashboard_ui.not_exposed')" :detail="__('dashboard_ui.queue_status_note')" />
        </div>
        <div class="ui-signal">
            <span class="ui-signal__label">{{ __('dashboard_ui.failed_jobs') }}</span>
            <x-ui.health-indicator status="not_configured" :label="__('dashboard_ui.not_exposed')" :detail="__('dashboard_ui.failed_jobs_note')" />
        </div>
        <div class="ui-signal">
            <span class="ui-signal__label">{{ __('dashboard_ui.recent_health_failures') }}</span>
            <x-ui.health-indicator
                :status="$recentHealthFailures->isEmpty() ? 'verified' : 'failed'"
                :label="$recentHealthFailures->count()"
                :detail="__('dashboard_ui.latest_failed_sample', ['count' => $health['recentErrors']->count()])"
            />
        </div>
        <div class="ui-signal">
            <span class="ui-signal__label">{{ __('dashboard_ui.server_availability') }}</span>
            <x-ui.health-indicator
                :status="$summary['servers']['offline'] === 0 ? 'verified' : 'failed'"
                :label="__('dashboard_ui.online_of_total', ['online' => $summary['servers']['online'], 'total' => $summary['servers']['total']])"
                :detail="__('dashboard_ui.authoritative_server_count')"
            />
        </div>
    </div>
    @if ($summary['servers']['total'] === 0)
        <x-ui.empty-state :title="__('ui.dashboard.no_servers')" />
    @endif
</x-ui.info-card>

<section class="ui-dashboard-section" aria-labelledby="deployment-overview-title">
    <div class="ui-section-heading">
        <div>
            <h2 id="deployment-overview-title">{{ __('dashboard_ui.deployment_overview_title') }}</h2>
            <p>{{ __('dashboard_ui.deployment_overview_description') }}</p>
        </div>
    </div>
    <div class="ui-dashboard-stat-grid">
        <x-ui.stat-card
            :label="__('dashboard_ui.deployments_today_visible')"
            :value="$visibleToday->count()"
            :context="__('dashboard_ui.latest_deployment_scope', ['count' => $recentDeployments->count()])"
            level="info"
        />
        <x-ui.stat-card :label="__('ui.dashboard.deployments_succeeded')" :value="$summary['deployments']['succeeded']" :context="__('dashboard_ui.all_time')" level="success" />
        <x-ui.stat-card :label="__('ui.dashboard.deployments_failed')" :value="$summary['deployments']['failed']" :context="__('dashboard_ui.all_time')" :level="$summary['deployments']['failed'] > 0 ? 'danger' : 'neutral'" />
        <x-ui.stat-card :label="__('ui.dashboard.deployments_running')" :value="$summary['deployments']['running']" :context="__('dashboard_ui.current_status_count')" level="info" />
        <x-ui.stat-card :label="__('dashboard_ui.average_duration')" :value="$averageRecentDuration ?? '—'" :context="__('dashboard_ui.average_duration_scope', ['count' => $recentDurations->count()])" />
    </div>
    <div class="ui-recent-release-strip">
        <h3>{{ __('ui.dashboard.recent_releases_title') }}</h3>
        @if ($recentReleases->isEmpty())
            <span class="muted">{{ __('ui.dashboard.no_releases') }}</span>
        @else
            <div>
                @foreach ($recentReleases as $release)
                    <a href="{{ route('deployments.show', $release) }}" class="ui-release-link">
                        <span class="release-tag">{{ $release->displayLabel() }}</span>
                        <span>{{ $release->project->name }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</section>

<div class="ui-ops-grid ui-dashboard-section">
    <x-ui.info-card
        :title="__('dashboard_ui.agent_fleet_title')"
        :description="__('dashboard_ui.agent_fleet_description', ['count' => $visibleAgentCount])"
    >
        <dl class="ui-meta-grid ui-dashboard-metrics">
            <div><dt>{{ __('ui.dashboard.agents_total') }}</dt><dd>{{ $summary['agents']['total'] }}</dd></div>
            <div><dt>{{ __('dashboard_ui.online_visible') }}</dt><dd class="stat-good">{{ $onlineAgents->count() }}</dd></div>
            <div><dt>{{ __('dashboard_ui.offline_visible') }}</dt><dd class="{{ $offlineAgents->isNotEmpty() ? 'stat-bad' : '' }}">{{ $offlineAgents->count() }}</dd></div>
            <div><dt>{{ __('dashboard_ui.outdated_artifact_agents') }}</dt><dd class="{{ $outdatedAgents->isNotEmpty() ? 'stat-warn' : '' }}">{{ $outdatedAgents->count() }}</dd></div>
            <div class="ui-meta-grid__full"><dt>{{ __('dashboard_ui.production_build_agents') }}</dt><dd>— <span class="muted">{{ __('dashboard_ui.production_build_agents_unavailable') }}</span></dd></div>
        </dl>
        @if ($agents->isEmpty())
            <x-ui.empty-state :title="__('ui.dashboard.no_agents')" />
        @else
            <div class="ui-agent-card-grid">
                @foreach ($agents->take(6) as $agent)
                    <x-ui.agent-status-card :agent="$agent" />
                @endforeach
            </div>
        @endif
    </x-ui.info-card>

    <x-ui.info-card
        :title="__('dashboard_ui.artifact_overview_title')"
        :description="__('dashboard_ui.artifact_overview_description')"
    >
        <dl class="ui-meta-grid ui-dashboard-metrics">
            <div><dt>{{ __('dashboard_ui.available_artifacts') }}</dt><dd>— <span class="muted">{{ __('dashboard_ui.not_exposed') }}</span></dd></div>
            <div><dt>{{ __('dashboard_ui.failed_artifacts') }}</dt><dd>— <span class="muted">{{ __('dashboard_ui.not_exposed') }}</span></dd></div>
            <div><dt>{{ __('dashboard_ui.linked_recent_artifacts') }}</dt><dd>{{ $recentArtifactLinked->count() }}</dd></div>
            <div><dt>{{ __('dashboard_ui.missing_required_artifacts') }}</dt><dd class="{{ $recentArtifactMissing->isNotEmpty() ? 'stat-bad' : '' }}">{{ $recentArtifactMissing->count() }}</dd></div>
        </dl>
        <x-ui.alert-panel level="neutral" :title="__('dashboard_ui.artifact_inventory_unavailable_title')">
            {{ __('dashboard_ui.artifact_inventory_unavailable_description', ['count' => $recentDeployments->count()]) }}
        </x-ui.alert-panel>
    </x-ui.info-card>
</div>

<x-ui.info-card
    id="attention-required"
    :title="__('dashboard_ui.attention_title')"
    :description="__('dashboard_ui.attention_description')"
    class="ui-dashboard-section"
>
    @if ($attentionCount === 0)
        <x-ui.alert-panel level="success" :title="__('ui.dashboard.health_all_clear')">
            {{ __('dashboard_ui.attention_clear_scope') }}
        </x-ui.alert-panel>
    @else
        <div class="ui-attention-list">
            @foreach ($health['problemAgents'] as $row)
                <a class="ui-attention-item" href="{{ route('agents.show', $row['agent']) }}">
                    <x-ui.status-badge domain="agent" :status="$row['reason'] === 'offline' ? 'offline' : 'pending'" size="small" />
                    <span><strong>{{ $row['agent']->name ?? $row['agent']->public_id }}</strong><small>{{ $row['agent']->server->name }}</small></span>
                    <span class="ui-attention-item__action">{{ __('dashboard_ui.review') }}</span>
                </a>
            @endforeach
            @foreach ($health['problemDeployments'] as $row)
                <a class="ui-attention-item" href="{{ route('deployments.show', $row['deployment']) }}">
                    <x-ui.status-badge
                        domain="deployment"
                        :status="$row['deployment']->status"
                        :label="$row['reason'] === 'stalled' ? __('ui.dashboard.reason_stalled') : ($row['reason'] === 'long_running' ? __('ui.dashboard.reason_long_running') : null)"
                        size="small"
                    />
                    <span>
                        <strong>{{ $row['deployment']->project->name }}</strong>
                        <small>{{ $row['deployment']->failure_message ? \Illuminate\Support\Str::limit($row['deployment']->failure_message, 90) : $row['deployment']->created_at->diffForHumans() }}</small>
                    </span>
                    <span class="ui-attention-item__action">{{ __('dashboard_ui.review') }}</span>
                </a>
            @endforeach
            @foreach ($recentArtifactMissing as $deployment)
                <a class="ui-attention-item" href="{{ route('deployments.show', $deployment) }}">
                    <x-ui.status-badge domain="artifact" status="missing" size="small" />
                    <span><strong>{{ $deployment->project->name }}</strong><small>{{ __('dashboard_ui.required_artifact_not_linked') }}</small></span>
                    <span class="ui-attention-item__action">{{ __('dashboard_ui.review') }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-ui.info-card>

<x-ui.info-card
    id="recent-deployments"
    :title="__('dashboard_ui.recent_timeline_title')"
    :description="__('dashboard_ui.recent_timeline_description', ['count' => $recentDeployments->count()])"
    class="ui-dashboard-section"
>
    @if ($recentDeployments->isEmpty())
        <x-ui.empty-state :title="__('ui.dashboard.no_deployments')" />
    @else
        <x-ui.timeline :label="__('dashboard_ui.recent_timeline_title')" class="ui-dashboard-timeline">
            @foreach ($recentDeployments as $deployment)
                @php
                    $deploymentPresentation = \App\Support\Ui\StatusRegistry::resolve('deployment', $deployment->status);
                    $firstTask = $deployment->tasks->first();
                    $targetName = $deployment->tasks->count() > 1
                        ? __('ui.dashboard.multiple_targets', ['count' => $deployment->tasks->count()])
                        : ($firstTask?->deploymentTarget?->name ?? $deployment->server->name);
                    $healthStatus = ! $deployment->hasHttpHealthCheck()
                        ? 'not_configured'
                        : ($deployment->http_health_status === \App\Models\Deployment::HTTP_HEALTH_VERIFIED
                            ? 'verified'
                            : ($deployment->http_health_status === \App\Models\Deployment::HTTP_HEALTH_FAILED ? 'failed' : 'pending'));
                @endphp
                <li class="ui-timeline__item--{{ $deploymentPresentation['level'] }}">
                    <span class="ui-timeline__time">{{ $deployment->finished_at?->diffForHumans() ?? $deployment->created_at->diffForHumans() }}</span>
                    <span class="ui-timeline__event ui-dashboard-timeline__event">
                        <a class="ui-timeline__title" href="{{ route('deployments.show', $deployment) }}">{{ $deployment->project->name }}</a>
                        <x-ui.status-badge domain="deployment" :status="$deployment->status" size="small" />
                        <span class="muted">{{ $targetName }}</span>
                        <span class="muted ui-code-wrap">{{ $deployment->status === \App\Models\Deployment::STATUS_SUCCEEDED ? $deployment->displayLabel() : substr($deployment->commit_hash, 0, 10) }}</span>
                        <span class="muted">{{ __('dashboard_ui.duration_value', ['duration' => $deployment->durationSeconds() !== null ? gmdate('H:i:s', $deployment->durationSeconds()) : '—']) }}</span>
                        <x-ui.health-indicator :status="$healthStatus" :label="__('dashboard_ui.health_short')" />
                    </span>
                </li>
            @endforeach
        </x-ui.timeline>
    @endif
</x-ui.info-card>
@endsection
