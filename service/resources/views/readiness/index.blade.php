@extends('layouts.app')

@section('title', __('ui.readiness.title'))

@section('content')
<h1>{{ __('ui.readiness.title') }}</h1>
<p class="muted" style="margin-top:-0.6rem;">{{ __('ui.readiness.subtitle') }}</p>

<x-card :title="__('ui.readiness.section_github_title')">
    <div class="stat-row">
        @if ($githubConnected)
            <x-badge status="connected" />
            <span>{{ __('ui.readiness.section_github_connected') }}</span>
        @else
            <x-badge status="not_connected" />
            <span>{{ __('ui.readiness.section_github_not_connected') }}</span>
            @can('github.view')
                <a href="{{ route('github.accounts.index') }}">{{ __('ui.readiness.section_github_action') }}</a>
            @endcan
        @endif
    </div>
</x-card>

<x-card :title="__('ui.readiness.section_servers_title')">
    <p class="muted" style="margin-top:-0.4rem;">{{ __('ui.readiness.section_servers_intro') }}</p>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.readiness.agent_column') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($servers as $row)
            <tr>
                <td><a href="{{ route('servers.show', $row['server']) }}">{{ $row['server']->name }}</a></td>
                <td>
                    @if ($row['online'])
                        <x-badge status="online" />
                    @else
                        <x-badge status="offline" />
                    @endif
                </td>
                <td>
                    @if ($row['agent_status'] === 'approved')
                        <x-badge status="approved" />
                    @elseif ($row['agent_status'] === 'pending')
                        <x-badge status="pending_approval" />
                    @else
                        <x-badge status="no_agent" />
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="3"><x-empty-state>{{ __('ui.readiness.no_servers') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

<x-card :title="__('ui.readiness.section_projects_title')">
    <p class="muted" style="margin-top:-0.4rem;">{{ __('ui.readiness.section_projects_intro') }}</p>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }}</th>
            <th>{{ __('ui.readiness.configured_column') }}</th>
            <th>{{ __('ui.readiness.deployment_ready_column') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($projects as $row)
            <tr>
                <td><a href="{{ route('projects.show', $row['project']) }}">{{ $row['project']->name }}</a></td>
                <td>
                    @if ($row['configured'])
                        <x-badge status="configured" />
                    @else
                        <x-badge status="not_configured" />
                    @endif
                </td>
                <td>
                    @if ($row['deployment_ready'])
                        <x-badge status="ready" />
                    @else
                        <x-badge status="not_ready" />
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="3"><x-empty-state>{{ __('ui.readiness.no_projects') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
