@extends('layouts.app')

@section('title', 'Target ' . $task->deploymentTarget->name)

@section('content')
<h1>{{ $task->deploymentTarget->name }} &mdash; <x-badge :status="$task->status" /></h1>
<p class="muted">
    {{ __('ui.deployment_tasks.deployment_label') }}: <a href="{{ route('deployments.show', $task->deployment) }}">{{ $task->deployment->project->name }} @ <x-ltr>{{ substr($task->deployment->commit_hash, 0, 12) }}</x-ltr></a>
    &mdash; {{ __('ui.deployment_tasks.environment_label') }}: {{ $task->deploymentTarget->environment->name }}
    &mdash; {{ __('ui.deployment_tasks.server_label') }}: <a href="{{ route('servers.show', $task->deploymentTarget->server) }}">{{ $task->deploymentTarget->server->name }}</a>
    @if ($task->next_retry_at)
        &mdash; <span class="badge badge-queued">{{ __('ui.deployment_tasks.retry_scheduled', ['time' => $task->next_retry_at->diffForHumans()]) }}</span>
    @endif
</p>

@if (!empty($steps))
{{-- Task 4-5: pipeline step visualization + live status for the latest attempt --}}
<x-card :title="__('ui.deployments.pipeline_steps_title')">
    <div class="pipeline-steps">
        @foreach ($steps as $step)
            @php
                $icon = match ($step['status']) {
                    'succeeded' => '✓',
                    'failed' => '✗',
                    'running' => '⏳',
                    'skipped' => '—',
                    default => '○',
                };
            @endphp
            <span class="pipeline-step step-{{ $step['status'] }}" title="{{ __('status.' . $step['status']) }}@if($step['duration_ms'] !== null) — {{ $step['duration_ms'] }}ms @endif">
                <span class="step-icon">{{ $icon }}</span> {{ $step['name'] }}
            </span>
        @endforeach
    </div>
    @if ($task->status === 'running')
    <div class="live-status-grid" style="margin-top:0.6rem;">
        <div class="live-stat">
            <span class="label">{{ __('ui.deployments.current_step') }}</span>
            <strong>{{ $currentStep['name'] ?? __('ui.deployments.no_current_step') }}</strong>
        </div>
    </div>
    @endif
</x-card>
@endif

@if ($task->status === 'succeeded')
<x-card>
    @can('rollback', $task->deployment->project)
    <form method="POST" action="{{ route('deployment-tasks.rollback', $task) }}" id="rollback-task-form" style="display:inline">
        @csrf
    </form>
    <button type="button" class="btn" onclick="document.getElementById('rollback-task-dialog').showModal()">{{ __('ui.deployment_tasks.rollback_target_button') }}</button>
    <x-confirm-dialog
        id="rollback-task-dialog"
        form-id="rollback-task-form"
        :title="__('ui.deployment_tasks.rollback_target_button')"
        :confirm-label="__('ui.deployment_tasks.rollback_target_button')"
        :danger="false"
    >
        {{-- Task 8: explicit from/to instead of a generic confirm --}}
        <p>{{ __('ui.deployment_tasks.rollback_target_confirm_detailed', ['target' => $task->deploymentTarget->name, 'from' => $currentTargetCommit?->displayLabel() ?? '—', 'to' => $task->deployment->displayLabel()]) }}</p>
        <table style="margin-top:0.4rem;">
            <tr><th>{{ __('ui.deployments.rollback_from') }}</th><td><span class="release-tag">{{ $currentTargetCommit?->displayLabel() ?? '—' }}</span></td></tr>
            <tr><th>{{ __('ui.deployments.rollback_to') }}</th><td><span class="release-tag">{{ $task->deployment->displayLabel() }}</span></td></tr>
        </table>
    </x-confirm-dialog>
    @endcan

    <h3 style="margin-top:1.5rem;">{{ __('ui.deployment_tasks.health_check_title') }}</h3>
    <form method="POST" action="{{ route('deployment-tasks.health-check', $task) }}" style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap;">
        @csrf
        <div style="flex:1;min-width:220px;">
            <label style="margin-top:0;">{{ __('ui.deployment_tasks.url_to_check') }}</label>
            <input type="text" name="url" placeholder="{{ __('ui.deployment_tasks.url_placeholder') }}" dir="ltr" style="text-align:left;" required>
        </div>
        <div style="width:120px;">
            <label style="margin-top:0;">{{ __('ui.deployment_tasks.expected_status') }}</label>
            <input type="text" name="expected_status" value="200">
        </div>
        <button type="submit" class="btn btn-primary">{{ __('ui.deployment_tasks.run_check_button') }}</button>
    </form>

    @forelse ($task->healthChecks as $check)
        <p style="margin:0.6rem 0 0;">
            <span class="badge badge-{{ $check->status === 'success' ? 'succeeded' : ($check->status === 'failed' ? 'failed' : 'pending_approval') }}">{{ $check->status === 'success' ? __('status.success') : ($check->status === 'failed' ? __('status.failed') : __('status.pending')) }}</span>
            <span class="muted">{{ $check->type }} — {{ $check->result_message }} ({{ $check->checked_at?->diffForHumans() ?? __('status.pending') }})</span>
        </p>
    @empty
        <p style="margin-top:0.6rem;"><x-empty-state>{{ __('ui.deployment_tasks.no_health_checks') }}</x-empty-state></p>
    @endforelse
</x-card>
@endif

@if ($task->status === 'failed')
<x-card>
    @can('deploy', $task->deployment->project)
    <form method="POST" action="{{ route('deployment-tasks.retry', $task) }}" id="retry-task-form" style="display:inline">
        @csrf
    </form>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('retry-task-dialog').showModal()">{{ __('ui.deployment_tasks.retry_target_button') }}</button>
    <x-confirm-dialog
        id="retry-task-dialog"
        form-id="retry-task-form"
        :title="__('ui.deployment_tasks.retry_target_button')"
        :danger="false"
        :confirm-label="__('ui.deployment_tasks.retry_target_button')"
    >
        <p>{{ __('ui.deployment_tasks.retry_target_confirm', ['commit' => $task->deployment->displayLabel()]) }}</p>
    </x-confirm-dialog>
    @endcan
</x-card>
@endif

<x-card :title="__('ui.deployment_tasks.attempts_title')">
    @forelse ($task->agentJobs as $agentJob)
        <div style="margin-bottom:1.2rem;padding-bottom:1rem;border-bottom:1px solid #eee;">
            <h4 style="margin-bottom:0.3rem;">
                {{ __('ui.deployment_tasks.attempt_number', ['n' => $agentJob->attempt]) }}
                <x-badge :status="$agentJob->status" />
                <span class="muted">— {{ __('ui.deployment_tasks.agent_label', ['name' => $agentJob->agent->name ?? $agentJob->agent->public_id]) }}</span>
            </h4>
            <p class="muted" style="margin:0 0 0.5rem;">
                @if ($agentJob->finished_at)
                    {{ __('ui.deployment_tasks.started_finished_both', ['started' => $agentJob->started_at?->diffForHumans() ?? '—', 'finished' => $agentJob->finished_at->diffForHumans()]) }}
                @else
                    {{ __('ui.deployment_tasks.started_finished', ['started' => $agentJob->started_at?->diffForHumans() ?? '—']) }}
                @endif
            </p>

            @forelse ($agentJob->logEntries as $entry)
                <div style="margin-bottom:0.6rem;">
                    <strong>{{ $entry->sequence }}. {{ $entry->step_name }}</strong>
                    @if ($entry->exit_code === 0)
                        <span class="badge badge-succeeded">{{ __('ui.deployment_tasks.exit_success') }}</span>
                    @elseif ($entry->exit_code !== null)
                        <span class="badge badge-failed">{{ __('ui.deployment_tasks.exit_failed', ['code' => $entry->exit_code]) }}</span>
                    @endif
                    <span class="muted">(<x-ltr>{{ $entry->duration_ms }}ms</x-ltr>)</span>
                    <p class="muted" style="margin:0.2rem 0;"><x-ltr><code>{{ $entry->command }}</code></x-ltr></p>
                    <pre>{{ $entry->output }}</pre>
                </div>
            @empty
                <x-empty-state>{{ __('ui.deployment_tasks.no_log_entries') }}</x-empty-state>
            @endforelse
        </div>
    @empty
        <x-empty-state>{{ __('ui.deployment_tasks.no_attempts') }}</x-empty-state>
    @endforelse
</x-card>
@endsection
