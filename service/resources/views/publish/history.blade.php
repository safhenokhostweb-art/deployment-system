@extends('layouts.app')

@section('title', __('ui.publish_history.title'))

@section('content')
<h1>{{ __('ui.publish_history.title') }}</h1>

<x-card>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.created') }}</th>
            <th>{{ __('ui.common.project') }}</th>
            <th>{{ __('ui.local_agents.title') }}</th>
            <th>{{ __('ui.common.action') }}</th>
            <th>{{ __('ui.publish.changed_files_title') }}</th>
            <th>{{ __('ui.publish.result_commit_hash') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($publishJobs as $job)
            <tr>
                <td>{{ $job->created_at->diffForHumans() }}</td>
                <td><a href="{{ route('projects.show', $job->project) }}">{{ $job->project->name }}</a></td>
                <td><a href="{{ route('local-agents.show', $job->localAgent) }}">{{ $job->localAgent->name ?? $job->localAgent->public_id }}</a></td>
                <td>{{ __('ui.publish.action_' . $job->action) }}</td>
                <td><a href="{{ route('projects.publish.show', [$job->project, $job]) }}">{{ $job->files_changed_count ?? '—' }}</a></td>
                <td>
                    @if ($job->result_commit_hash)
                        <x-ltr><code>{{ substr($job->result_commit_hash, 0, 10) }}</code></x-ltr>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td><x-badge :status="$job->isLeaseExpired() ? 'awaiting_recovery' : $job->status" /></td>
                <td>
                    @can('local_agents.publish')
                        @if ($job->action === 'publish' && $job->status === 'succeeded' && in_array($job->id, $latestSucceededPublishIds, true) && $job->project->localProjectMappings->count() === 1)
                            <form method="POST" action="{{ route('projects.publish.rollback', $job->project) }}" id="rollback-history-{{ $job->id }}-form" style="display:inline">
                                @csrf
                                <input type="hidden" name="local_project_mapping_id" value="{{ $job->project->localProjectMappings->first()->id }}">
                            </form>
                            <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('rollback-history-{{ $job->id }}-dialog').showModal()">{{ __('ui.publish.rollback_button') }}</button>
                            <x-confirm-dialog
                                id="rollback-history-{{ $job->id }}-dialog"
                                form-id="rollback-history-{{ $job->id }}-form"
                                :title="__('ui.publish.rollback_button')"
                                :body="__('ui.publish.rollback_confirm')"
                                :confirm-label="__('ui.publish.rollback_button')"
                            />
                        @endif
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="8"><x-empty-state>{{ __('ui.publish_history.no_history') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>

    @if ($publishJobs->hasPages())
    <div style="display:flex;gap:0.6rem;margin-top:1rem;">
        @if ($publishJobs->previousPageUrl())
            <a href="{{ $publishJobs->previousPageUrl() }}" class="btn btn-small">{{ __('ui.publish_history.previous_page') }}</a>
        @endif
        @if ($publishJobs->nextPageUrl())
            <a href="{{ $publishJobs->nextPageUrl() }}" class="btn btn-small">{{ __('ui.publish_history.next_page') }}</a>
        @endif
    </div>
    @endif
</x-card>
@endsection
