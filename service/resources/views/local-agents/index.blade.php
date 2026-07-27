@extends('layouts.app')

@section('title', __('ui.local_agents.title'))

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ __('ui.local_agents.title') }}</h1>
    <div style="display:flex;gap:0.6rem;">
        @can('local_agents.manage')
        <form method="POST" action="{{ route('local-agents.create-registration') }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-primary">{{ __('ui.local_agents.create_registration_button') }}</button>
        </form>
        @endcan
        <a href="{{ route('local-agents.pending') }}" class="btn">{{ __('ui.local_agents.pending_button') }}</a>
    </div>
</div>

<x-card>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }} / public_id</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('status.online') }}</th>
            <th>{{ __('ui.local_agents.os') }}</th>
            <th>{{ __('ui.common.projects') }}</th>
            <th>{{ __('ui.common.last_heartbeat') }}</th>
            <th>{{ __('ui.local_agents.last_publish') }}</th>
            <th>{{ __('ui.local_agents.errors') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($localAgents as $localAgent)
            @php
                $latestJob = $localAgent->publishJobs->first();
                $failedCount = $localAgent->publishJobs->where('status', 'failed')->count();
            @endphp
            <tr>
                <td><a href="{{ route('local-agents.show', $localAgent) }}">{{ $localAgent->name ?? $localAgent->public_id }}</a></td>
                <td><x-badge :status="$localAgent->status" /></td>
                <td>
                    @if ($localAgent->isOnline())
                        <x-badge status="online" />
                    @else
                        <x-badge status="offline" />
                    @endif
                </td>
                <td>{{ $localAgent->operating_system ?? '—' }}</td>
                <td>{{ $localAgent->projectMappings->pluck('project.name')->implode(', ') ?: '—' }}</td>
                <td>{{ $localAgent->last_seen_at?->diffForHumans() ?? __('status.never') }}</td>
                <td>
                    @if ($latestJob)
                        <a href="{{ route('projects.publish.show', [$latestJob->project, $latestJob]) }}"><x-badge :status="$latestJob->status" /></a>
                        <span class="muted">{{ $latestJob->created_at->diffForHumans() }}</span>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td>
                    @if ($failedCount > 0)
                        <span class="badge badge-failed">{{ __('ui.local_agents.errors_count', ['count' => $failedCount]) }}</span>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8"><x-empty-state>{{ __('ui.local_agents.no_local_agents') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
