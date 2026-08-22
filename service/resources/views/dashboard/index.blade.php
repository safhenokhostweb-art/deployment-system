@extends('layouts.app')

@section('title', __('ui.dashboard.title'))

@section('content')
<h1>{{ __('ui.dashboard.title') }}</h1>
<p class="muted" style="margin-top:-0.6rem;">{{ __('ui.dashboard.subtitle') }}</p>

{{-- Task 2: Summary Cards --}}
<div class="summary-grid">
    <x-card :title="__('ui.dashboard.card_servers')">
        <div class="stat-row"><span>{{ __('ui.dashboard.servers_total') }}</span><strong>{{ $summary['servers']['total'] }}</strong></div>
        <div class="stat-row"><span>{{ __('ui.dashboard.servers_online') }}</span><strong class="stat-good">{{ $summary['servers']['online'] }}</strong></div>
        <div class="stat-row"><span>{{ __('ui.dashboard.servers_offline') }}</span><strong class="stat-bad">{{ $summary['servers']['offline'] }}</strong></div>
    </x-card>

    <x-card :title="__('ui.dashboard.card_agents')">
        <div class="stat-row"><span>{{ __('ui.dashboard.agents_total') }}</span><strong>{{ $summary['agents']['total'] }}</strong></div>
        <div class="stat-row"><span>{{ __('ui.dashboard.agents_approved') }}</span><strong class="stat-good">{{ $summary['agents']['approved'] }}</strong></div>
        <div class="stat-row"><span>{{ __('ui.dashboard.agents_pending') }}</span><strong class="stat-warn">{{ $summary['agents']['pending_approval'] }}</strong></div>
        <div class="stat-row"><span>{{ __('ui.dashboard.agents_disabled') }}</span><strong class="stat-bad">{{ $summary['agents']['disabled'] }}</strong></div>
    </x-card>

    <x-card :title="__('ui.dashboard.card_projects')">
        <div class="stat-row"><span>{{ __('ui.dashboard.projects_total') }}</span><strong>{{ $summary['projects']['total'] }}</strong></div>
        <div class="stat-row"><span>{{ __('ui.dashboard.projects_active') }}</span><strong class="stat-good">{{ $summary['projects']['active'] }}</strong></div>
    </x-card>

    <x-card :title="__('ui.dashboard.card_deployments')">
        <div class="stat-row"><span>{{ __('ui.dashboard.deployments_total') }}</span><strong>{{ $summary['deployments']['total'] }}</strong></div>
        <div class="stat-row"><span>{{ __('ui.dashboard.deployments_succeeded') }}</span><strong class="stat-good">{{ $summary['deployments']['succeeded'] }}</strong></div>
        <div class="stat-row"><span>{{ __('ui.dashboard.deployments_failed') }}</span><strong class="stat-bad">{{ $summary['deployments']['failed'] }}</strong></div>
        <div class="stat-row"><span>{{ __('ui.dashboard.deployments_running') }}</span><strong class="stat-info">{{ $summary['deployments']['running'] }}</strong></div>
    </x-card>
</div>

{{-- Task 7: Quick Actions --}}
<x-card :title="__('ui.dashboard.quick_actions_title')">
    <div class="quick-actions">
        @can('projects.create')
        <a href="{{ route('projects.create') }}" class="btn btn-primary">{{ __('ui.dashboard.quick_action_new_project') }}</a>
        @endcan
        @can('servers.create')
        <a href="{{ route('servers.create') }}" class="btn">{{ __('ui.dashboard.quick_action_add_server') }}</a>
        @endcan
        @can('deployments.view')
        <a href="#recent-deployments" class="btn">{{ __('ui.dashboard.quick_action_view_deployments') }}</a>
        @endcan
        @can('agents.approve')
        <a href="{{ route('agents.pending') }}" class="btn">{{ __('ui.dashboard.quick_action_view_pending_agents') }}</a>
        @endcan
    </div>
</x-card>

{{-- Task 3: System Health Overview --}}
<x-card :title="__('ui.dashboard.health_title')">
    @if ($health['problemAgents']->isEmpty() && $health['problemDeployments']->isEmpty() && $health['recentErrors']->isEmpty())
        <x-alert type="success">{{ __('ui.dashboard.health_all_clear') }}</x-alert>
    @else
        @if ($health['problemAgents']->isNotEmpty())
        <h4>{{ __('ui.dashboard.health_problem_agents_title') }}</h4>
        <table>
            <thead><tr>
                <th>{{ __('ui.common.name') }}</th>
                <th>{{ __('ui.common.server') }}</th>
                <th>{{ __('ui.common.status') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($health['problemAgents'] as $row)
                <tr>
                    <td><a href="{{ route('agents.show', $row['agent']) }}">{{ $row['agent']->name ?? $row['agent']->public_id }}</a></td>
                    <td>{{ $row['agent']->server->name }}</td>
                    <td>{{ $row['reason'] === 'pending_approval' ? __('status.pending_approval') : __('status.offline') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif

        @if ($health['problemDeployments']->isNotEmpty())
        <h4 style="margin-top:1.2rem;">{{ __('ui.dashboard.health_problem_deployments_title') }}</h4>
        <table>
            <thead><tr>
                <th>{{ __('ui.common.project') }}</th>
                <th>{{ __('ui.common.status') }}</th>
                <th>{{ __('ui.common.created') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($health['problemDeployments'] as $row)
                <tr>
                    <td><a href="{{ route('deployments.show', $row['deployment']) }}">{{ $row['deployment']->project->name }}</a></td>
                    <td>
                        @if ($row['reason'] === 'failed')
                            <x-badge status="failed" />
                        @elseif ($row['reason'] === 'stalled')
                            <span class="badge badge-failed">{{ __('ui.dashboard.reason_stalled') }}</span>
                        @else
                            <span class="badge badge-partial_success">{{ __('ui.dashboard.reason_long_running') }}</span>
                        @endif
                    </td>
                    <td>{{ $row['deployment']->created_at->diffForHumans() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif

        @if ($health['recentErrors']->isNotEmpty())
        <h4 style="margin-top:1.2rem;">{{ __('ui.dashboard.health_recent_errors_title') }}</h4>
        <table>
            <thead><tr>
                <th>{{ __('ui.dashboard.error_type') }}</th>
                <th>{{ __('ui.common.project') }}</th>
                <th>{{ __('ui.common.created') }}</th>
                <th>{{ __('ui.common.status') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($health['recentErrors'] as $deployment)
                <tr>
                    <td>{{ $deployment->action }}</td>
                    <td><a href="{{ route('deployments.show', $deployment) }}">{{ $deployment->project->name }}</a></td>
                    <td>{{ $deployment->finished_at?->diffForHumans() ?? $deployment->created_at->diffForHumans() }}</td>
                    <td><x-badge :status="$deployment->status" /></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    @endif
</x-card>

{{-- Phase 4-I Task 9: Recent Releases Widget --}}
<x-card :title="__('ui.dashboard.recent_releases_title')">
    <table>
        <thead><tr>
            <th>{{ __('ui.deployments.release_label') }}</th>
            <th>{{ __('ui.common.project') }}</th>
            <th>{{ __('ui.projects.history_user') }}</th>
            <th>{{ __('ui.common.created') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($recentReleases as $release)
            <tr>
                <td><a href="{{ route('deployments.show', $release) }}"><span class="release-tag">{{ $release->displayLabel() }}</span></a></td>
                <td>{{ $release->project->name }}</td>
                <td>{{ $release->triggered_by ?? $release->trigger }}</td>
                <td>{{ $release->finished_at?->diffForHumans() ?? $release->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="4"><x-empty-state>{{ __('ui.dashboard.no_releases') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

{{-- Task 4: Recent Deployments Widget --}}
<x-card :title="__('ui.dashboard.recent_deployments_title')" id="recent-deployments">
    <table>
        <thead><tr>
            <th>{{ __('ui.common.project') }}</th>
            <th>{{ __('ui.common.environment') }}</th>
            <th>{{ __('ui.dashboard.target') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.common.created') }}</th>
            <th>{{ __('ui.dashboard.error_result') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($recentDeployments as $deployment)
            @php
                $firstTask = $deployment->tasks->first();
                $environmentName = $firstTask?->deploymentTarget?->environment?->name ?? $deployment->project->environment;
                $target = $deployment->tasks->count() > 1
                    ? __('ui.dashboard.multiple_targets', ['count' => $deployment->tasks->count()])
                    : ($firstTask?->deploymentTarget?->name ?? $deployment->server->name);
            @endphp
            <tr>
                <td><a href="{{ route('deployments.show', $deployment) }}">{{ $deployment->project->name }}</a></td>
                <td>{{ $environmentName }}</td>
                <td>{{ $target }}</td>
                <td><x-badge :status="$deployment->status" /></td>
                <td>{{ $deployment->created_at->diffForHumans() }}</td>
                <td>{{ $deployment->status === 'failed' && $deployment->failure_message ? \Illuminate\Support\Str::limit($deployment->failure_message, 40) : __('ui.dashboard.no_result') }}</td>
            </tr>
        @empty
            <tr><td colspan="6"><x-empty-state>{{ __('ui.dashboard.no_deployments') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

{{-- Task 5: Agent Status Widget --}}
<x-card :title="__('ui.dashboard.agent_widget_title')">
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }}</th>
            <th>{{ __('ui.common.server') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.common.version') }}</th>
            <th>{{ __('ui.common.last_heartbeat') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($agents as $agent)
            <tr>
                <td><a href="{{ route('agents.show', $agent) }}">{{ $agent->name ?? $agent->public_id }}</a></td>
                <td><a href="{{ route('servers.show', $agent->server) }}">{{ $agent->server->name }}</a></td>
                <td>
                    @if ($agent->status === 'approved')
                        @if ($agent->isOnline())
                            <x-badge status="online" />
                        @else
                            <x-badge status="offline" />
                        @endif
                    @else
                        <x-badge :status="$agent->status" />
                    @endif
                </td>
                <td>{{ $agent->agent_version ?? '—' }}</td>
                <td>{{ $agent->last_seen_at?->diffForHumans() ?? __('status.never') }}</td>
            </tr>
        @empty
            <tr><td colspan="5"><x-empty-state>{{ __('ui.dashboard.no_agents') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

{{-- Task 6: Server Overview Widget --}}
<x-card :title="__('ui.dashboard.server_widget_title')">
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }}</th>
            <th>{{ __('ui.dashboard.server_type') }}</th>
            <th>{{ __('ui.dashboard.connected_agent') }}</th>
            <th>{{ __('ui.common.status') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($serverOverview as $row)
            <tr>
                <td><a href="{{ route('servers.show', $row['server']) }}">{{ $row['server']->name }}</a></td>
                <td>{{ $row['type'] === 'agent_based' ? __('ui.dashboard.server_type_agent_based') : __('ui.dashboard.server_type_legacy') }}</td>
                <td>{{ $row['connectedAgent']?->name ?? $row['connectedAgent']?->public_id ?? __('ui.dashboard.no_connected_agent') }}</td>
                <td>
                    @if ($row['isOnline'])
                        <x-badge status="online" />
                    @else
                        <x-badge status="offline" />
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4"><x-empty-state>{{ __('ui.dashboard.no_servers') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
