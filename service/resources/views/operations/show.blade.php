@extends('layouts.app')

@section('title', __('ui.operations.detail_title'))

@section('content')
<h1>{{ __('ui.operations.type_' . $job->operation_type) }} — <x-badge :status="$job->status" /></h1>
<p class="muted" style="margin-top:-0.6rem;">
    <a href="{{ route('servers.show', $job->server) }}">{{ $job->server->name }}</a>
    @if ($job->project)
        — {{ $job->project->name }}
    @endif
</p>

<x-card>
    <table>
        <tr><th>{{ __('ui.common.server') }}</th><td><a href="{{ route('servers.show', $job->server) }}">{{ $job->server->name }}</a></td></tr>
        @if ($job->project)
        <tr><th>{{ __('ui.common.project') }}</th><td><a href="{{ route('projects.show', $job->project) }}">{{ $job->project->name }}</a></td></tr>
        @endif
        <tr><th>{{ __('ui.operations.requested_by_label') }}</th><td>{{ $job->requested_by ?? '—' }}</td></tr>
        <tr><th>{{ __('ui.common.status') }}</th><td><x-badge :status="$job->status" /></td></tr>
        <tr><th>{{ __('ui.deployments.duration') }}</th><td>@if($job->durationSeconds() !== null)<x-ltr>{{ gmdate('H:i:s', $job->durationSeconds()) }}</x-ltr>@else — @endif</td></tr>
        @if ($job->error_message)
        <tr><th>{{ __('ui.operations.error_title') }}</th><td>{{ $job->error_message }}</td></tr>
        @endif
    </table>
</x-card>

{{-- Task 8: Operation Timeline --}}
<x-card :title="__('ui.operations.timeline_title')">
    <ul class="timeline">
        <li class="tl-succeeded"><span class="tl-time"><x-ltr>{{ $job->created_at->format('H:i') }}</x-ltr></span> — <span class="tl-label">{{ __('ui.operations.timeline_created') }}</span></li>
        @if ($job->started_at)
        <li class="tl-succeeded"><span class="tl-time"><x-ltr>{{ $job->started_at->format('H:i') }}</x-ltr></span> — <span class="tl-label">{{ __('ui.operations.timeline_agent_received') }}</span></li>
        @endif
        @if ($job->finished_at)
        <li class="tl-{{ $job->status === 'succeeded' ? 'succeeded' : 'failed' }}"><span class="tl-time"><x-ltr>{{ $job->finished_at->format('H:i') }}</x-ltr></span> — <span class="tl-label">{{ __('status.' . $job->status) }}</span></li>
        @endif
    </ul>
</x-card>

{{-- Task 8: output shown in full but already redacted at write time
     (SecretRedactor, same as deployment log output) — never a raw,
     unsanitized command result. --}}
<x-card :title="__('ui.operations.output_title')">
    @if ($job->output)
        <pre>{{ $job->output }}</pre>
    @else
        <x-empty-state>{{ __('ui.operations.no_output') }}</x-empty-state>
    @endif
</x-card>
@endsection
