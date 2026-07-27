@extends('layouts.app')

@section('title', __('ui.changes.title'))

@section('content')
<h1>{{ __('ui.changes.title') }} — {{ $project->name }}</h1>
<p class="muted"><x-badge :status="$changeDetection->status" /></p>

<x-card>
    <p>{{ __('ui.publish.files_changed_count', ['count' => $changeDetection->files_changed_count]) }}</p>

    @if ($changeDetection->files_changed_count === 0)
        <p class="muted">{{ __('ui.publish.no_changes') }}</p>
    @else
        <ul>
            @foreach ($changeDetection->changed_files as $file)
                <li><x-ltr><code>{{ $file }}</code></x-ltr></li>
            @endforeach
        </ul>
    @endif

    @if (in_array($changeDetection->status, ['detected', 'waiting_approval']) && $changeDetection->files_changed_count > 0)
        @can('local_agents.publish')
        <form method="POST" action="{{ route('projects.changes.approve', [$project, $changeDetection]) }}" style="margin-top:1rem;">
            @csrf
            <label for="commit_message">{{ __('ui.changes.suggested_commit_message_label') }}</label>
            <input type="text" id="commit_message" name="commit_message" maxlength="500" style="width:100%;" value="{{ $changeDetection->suggested_commit_message }}">
            <button type="submit" class="btn btn-primary" style="margin-top:0.6rem;">{{ __('ui.changes.approve_button') }}</button>
        </form>

        <form method="POST" action="{{ route('projects.changes.ignore', [$project, $changeDetection]) }}" id="ignore-change-form" style="display:inline;margin-top:0.6rem;">
            @csrf
        </form>
        <button type="button" class="btn btn-danger btn-small" style="margin-top:0.6rem;" onclick="document.getElementById('ignore-change-dialog').showModal()">{{ __('ui.changes.ignore_button') }}</button>
        <x-confirm-dialog
            id="ignore-change-dialog"
            form-id="ignore-change-form"
            :title="__('ui.changes.ignore_button')"
            :body="__('ui.changes.ignore_confirm')"
            :confirm-label="__('ui.changes.ignore_button')"
        />
        @endcan
    @elseif ($changeDetection->status === 'approved')
        <p class="muted">{{ __('ui.changes.status_approved') }}</p>
        @if ($changeDetection->publishJob)
            <a href="{{ route('projects.publish.show', [$project, $changeDetection->publishJob]) }}" class="btn">{{ __('ui.changes.view_publish_job') }}</a>
        @endif
    @elseif ($changeDetection->status === 'published')
        <p class="muted">{{ __('ui.changes.status_published') }}</p>
        @if ($changeDetection->publishJob)
            <a href="{{ route('projects.publish.show', [$project, $changeDetection->publishJob]) }}" class="btn">{{ __('ui.changes.view_publish_job') }}</a>
        @endif
    @elseif ($changeDetection->status === 'ignored')
        <p class="muted">{{ __('ui.changes.status_ignored') }}</p>
    @endif
</x-card>
@endsection
