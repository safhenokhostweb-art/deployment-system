@extends('layouts.app')

@section('title', 'Deployment ' . $deployment->public_id)

@section('content')
@php
    $firstTask = $deployment->tasks->first();
    $environmentName = $firstTask?->deploymentTarget?->environment?->name ?? $deployment->project->environment;
    $targetName = $deployment->tasks->count() > 1
        ? __('ui.dashboard.multiple_targets', ['count' => $deployment->tasks->count()])
        : ($firstTask?->deploymentTarget?->name ?? $deployment->server->name);
@endphp
<h1><span class="release-tag">{{ $deployment->displayLabel() }}</span> — {{ $deployment->project->name }} <x-badge :status="$deployment->status" /></h1>

{{-- Task 3: Overview --}}
<x-card :title="__('ui.deployments.overview_title')">
    <table>
        <tr><th>{{ __('ui.common.project') }}</th><td><a href="{{ route('projects.show', $deployment->project) }}">{{ $deployment->project->name }}</a></td></tr>
        <tr><th>{{ __('ui.common.environment') }}</th><td>{{ $environmentName }}</td></tr>
        <tr><th>{{ __('ui.dashboard.target') }}</th><td>{{ $targetName }}</td></tr>
        <tr><th>{{ __('ui.common.commit') }}</th><td><x-ltr><code>{{ $deployment->commit_hash }}</code></x-ltr></td></tr>
        <tr><th>{{ __('ui.common.branch') }}</th><td><x-ltr>{{ $deployment->branch }}</x-ltr></td></tr>
        <tr><th>{{ __('ui.deployments.triggered_by') }}</th><td>{{ $deployment->trigger }} @if($deployment->triggered_by) {{ __('ui.common.by', ['who' => $deployment->triggered_by]) }} @endif</td></tr>
        <tr><th>{{ __('ui.common.status') }}</th><td><x-badge :status="$deployment->status" /></td></tr>
        @if ($deployment->release_notes)
        <tr><th>{{ __('ui.deployments.release_notes_field') }}</th><td>{{ $deployment->release_notes }}</td></tr>
        @endif
        <tr><th>{{ __('ui.deployments.duration') }}</th><td>@if($deployment->durationSeconds() !== null)<x-ltr>{{ gmdate('H:i:s', $deployment->durationSeconds()) }}</x-ltr>@else — @endif</td></tr>
        <tr><th>{{ __('ui.deployments.deployment_id') }}</th><td><x-ltr><code>{{ $deployment->public_id }}</code></x-ltr></td></tr>
        @if ($deployment->previous_commit_hash)
        <tr><th>{{ __('ui.deployments.previous_commit') }}</th><td><x-ltr><code>{{ $deployment->previous_commit_hash }}</code></x-ltr></td></tr>
        @endif
        @if ($deployment->rollbackOf)
        <tr><th>{{ __('ui.deployments.rollback_of') }}</th><td><a href="{{ route('deployments.show', $deployment->rollbackOf) }}">{{ __('ui.deployments.rollback_of_value', ['id' => $deployment->rollbackOf->public_id]) }}</a></td></tr>
        @endif
        @php $rollbackDeployment = \App\Models\Deployment::where('rollback_of_deployment_id', $deployment->id)->latest()->first(); @endphp
        @if ($rollbackDeployment)
        <tr><th>{{ __('ui.deployments.rollback_status') }}</th><td>{{ __('ui.deployments.rolled_back_by') }} <a href="{{ route('deployments.show', $rollbackDeployment) }}"><x-ltr>{{ $rollbackDeployment->public_id }}</x-ltr></a> (<x-badge :status="$rollbackDeployment->status" />)</td></tr>
        @endif
        @if ($deployment->approved_by)
        <tr><th>{{ __('ui.deployments.approved_by') }}</th><td>{{ __('ui.deployments.approved_at', ['who' => $deployment->approved_by, 'when' => $deployment->approved_at]) }}</td></tr>
        @endif
        <tr><th>{{ __('ui.common.server') }}</th><td><a href="{{ route('servers.show', $deployment->server) }}">{{ $deployment->server->name }}</a></td></tr>
        <tr><th>{{ __('ui.deployments.queued') }}</th><td>{{ $deployment->queued_at?->diffForHumans() ?? '—' }}</td></tr>
        <tr><th>{{ __('ui.deployments.started') }}</th><td>{{ $deployment->started_at?->diffForHumans() ?? '—' }}</td></tr>
        <tr><th>{{ __('ui.deployments.finished') }}</th><td>{{ $deployment->finished_at?->diffForHumans() ?? '—' }}</td></tr>
        {{-- Root-cause fix (sandogh deployment overhaul, Phase 5): "the
        agent's commands exited 0" (finished_at, above) is NOT the same
        claim as "production is verifiably serving this release"
        (deployed_at — set only once activation and every health check
        this recipe defines have actually passed). Shown for every
        deployment, not just recipe-based ones, so the distinction is
        never silently different between the two. --}}
        <tr>
            <th>{{ __('ui.deployments.verified_deployed') }}</th>
            <td>
                @if ($deployment->deployed_at)
                    <span class="badge badge-succeeded">{{ __('ui.deployments.verified_deployed_yes') }}</span>
                    {{ $deployment->deployed_at->diffForHumans() }}
                @elseif ($deployment->finished_at)
                    <span class="badge badge-failed">{{ __('ui.deployments.verified_deployed_no') }}</span>
                @else
                    —
                @endif
            </td>
        </tr>
        @if ($deployment->failure_message)
        <tr>
            <th>{{ __('ui.deployments.failure') }}</th>
            <td>
                {{ $deployment->failure_message }}
                @if ($deployment->failure_code)
                    <br><span class="muted">{{ __('ui.deployments.failure_code', ['code' => $deployment->failure_code]) }}</span>
                    @php $guidance = \App\Support\Deployment\FailureCodeGuidance::forCode($deployment->failure_code); @endphp
                    @if ($guidance)
                        <p class="muted" style="margin-top:0.3rem;">{{ $guidance }}</p>
                    @endif
                @endif
            </td>
        </tr>
        @endif
    </table>

    <div style="margin-top:1rem;display:flex;gap:0.6rem;">
        @if ($deployment->status === 'pending_approval')
            @can('deploy', $deployment->project)
            <form method="POST" action="{{ route('deployments.approve', $deployment) }}">
                @csrf
                <button type="submit" class="btn btn-primary">{{ __('ui.deployments.approve_button') }}</button>
            </form>
            <form method="POST" action="{{ route('deployments.cancel', $deployment) }}">
                @csrf
                <button type="submit" class="btn btn-danger">{{ __('ui.deployments.cancel_button') }}</button>
            </form>
            @endcan
        @elseif ($deployment->status === 'queued')
            @can('deploy', $deployment->project)
            <form method="POST" action="{{ route('deployments.cancel', $deployment) }}">
                @csrf
                <button type="submit" class="btn btn-danger">{{ __('ui.deployments.cancel_button') }}</button>
            </form>
            @endcan
        @elseif ($deployment->isRetryable())
            @can('deploy', $deployment->project)
            <form method="POST" action="{{ route('deployments.retry', $deployment) }}" id="retry-deployment-form" style="display:inline">
                @csrf
            </form>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('retry-deployment-dialog').showModal()">{{ __('ui.deployments.retry_button') }}</button>
            <x-confirm-dialog
                id="retry-deployment-dialog"
                form-id="retry-deployment-form"
                :title="__('ui.deployments.retry_button')"
                :danger="false"
                :confirm-label="__('ui.deployments.retry_button')"
            >
                <p>{{ __('ui.deployments.retry_confirm', ['commit' => $deployment->displayLabel()]) }}</p>
            </x-confirm-dialog>
            @endcan
        @elseif ($deployment->status === 'succeeded')
            @can('rollback', $deployment->project)
            <form method="POST" action="{{ route('deployments.rollback', $deployment) }}" id="rollback-deployment-form" style="display:inline">
                @csrf
            </form>
            <button type="button" class="btn" onclick="document.getElementById('rollback-deployment-dialog').showModal()">{{ __('ui.deployments.rollback_button') }}</button>
            <x-confirm-dialog
                id="rollback-deployment-dialog"
                form-id="rollback-deployment-form"
                :title="__('ui.deployments.rollback_button')"
                :danger="false"
                :confirm-label="__('ui.deployments.rollback_button')"
            >
                {{-- Task 8: explicit from/to instead of a generic "roll back to this commit?" --}}
                <p>{{ __('ui.deployments.rollback_confirm_detailed', ['from' => $currentLive?->displayLabel() ?? '—', 'to' => $deployment->displayLabel()]) }}</p>
                <table style="margin-top:0.4rem;">
                    <tr><th>{{ __('ui.deployments.rollback_from') }}</th><td><span class="release-tag">{{ $currentLive?->displayLabel() ?? '—' }}</span></td></tr>
                    <tr><th>{{ __('ui.deployments.rollback_to') }}</th><td><span class="release-tag">{{ $deployment->displayLabel() }}</span></td></tr>
                </table>
            </x-confirm-dialog>
            @endcan
        @endif
    </div>
</x-card>

{{-- Task 5: Live status --}}
@if (!$deployment->isTerminal() || $deployment->status === 'failed')
<x-card :title="__('ui.deployments.live_status_title')">
    <div class="live-status-grid">
        <div class="live-stat">
            <span class="label">{{ __('ui.deployments.current_step') }}</span>
            <strong>{{ $currentStep['name'] ?? __('ui.deployments.no_current_step') }}</strong>
        </div>
        <div class="live-stat">
            <span class="label">{{ __('ui.deployments.execution_time') }}</span>
            <strong><x-ltr>{{ $deployment->durationSeconds() !== null ? gmdate('H:i:s', $deployment->durationSeconds()) : '—' }}</x-ltr></strong>
        </div>
        @if ($deployment->status === 'failed' && $deployment->failure_message)
        <div class="live-stat">
            <span class="label">{{ __('ui.deployments.error_message') }}</span>
            <strong class="stat-bad">{{ \Illuminate\Support\Str::limit($deployment->failure_message, 80) }}</strong>
        </div>
        @endif
    </div>
    @php $lastEntry = $deployment->logEntries->last(); @endphp
    @if ($lastEntry)
        <span class="label" style="display:block;font-size:0.75rem;color:#6a737d;margin-bottom:0.2rem;">{{ __('ui.deployments.last_log_line') }}</span>
        <p class="muted" style="margin:0;"><x-ltr><code>{{ $lastEntry->step_name }}</code></x-ltr> — {{ \Illuminate\Support\Str::limit($lastEntry->output ?? '', 120) }}</p>
    @endif
</x-card>
@endif

{{-- Task 4 (Phase 4-I) / recipe-aware (sandogh Phase 4): step visualization —
same markup either way, $steps just comes from RecipeStepPresenter instead
of PipelineStepPresenter for a recipe-driven Project (see controller). --}}
@if (!empty($steps))
<x-card :title="$usesRecipe ? __('ui.deployments.recipe_steps_title') : __('ui.deployments.pipeline_steps_title')">
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
                $title = __('status.' . $step['status']);
                if ($step['duration_ms'] !== null) { $title .= ' — ' . $step['duration_ms'] . 'ms'; }
                if (($step['failure_code'] ?? null) !== null) { $title .= ' — ' . __('ui.deployments.failure_code', ['code' => $step['failure_code']]); }
            @endphp
            <span class="pipeline-step step-{{ $step['status'] }}" title="{{ $title }}">
                <span class="step-icon">{{ $icon }}</span> {{ $step['name'] }}
            </span>
        @endforeach
    </div>
</x-card>

{{-- Task 3: Timeline --}}
<x-card :title="__('ui.deployments.timeline_title')">
    <ul class="timeline">
        @if ($deployment->queued_at)
        <li class="tl-succeeded"><span class="tl-time"><x-ltr>{{ $deployment->queued_at->format('H:i') }}</x-ltr></span> — <span class="tl-label">{{ __('ui.deployments.timeline_deployment_queued') }}</span></li>
        @endif
        @if ($deployment->started_at)
        <li class="tl-succeeded"><span class="tl-time"><x-ltr>{{ $deployment->started_at->format('H:i') }}</x-ltr></span> — <span class="tl-label">{{ __('ui.deployments.timeline_deployment_started') }}</span></li>
        @endif
        @foreach ($steps as $step)
            @continue($step['status'] === 'pending' || $step['status'] === 'skipped')
            <li class="tl-{{ $step['status'] }}">
                <span class="tl-time"><x-ltr>{{ $step['started_at']?->format('H:i') ?? '—' }}</x-ltr></span>
                — <span class="tl-label">{{ $step['name'] }}</span>
                <x-badge :status="$step['status']" />
                @if ($step['duration_ms'] !== null)<span class="muted">(<x-ltr>{{ $step['duration_ms'] }}ms</x-ltr>)</span>@endif
            </li>
        @endforeach
        @if ($deployment->finished_at)
        <li class="tl-{{ $deployment->status === 'succeeded' ? 'succeeded' : 'failed' }}"><span class="tl-time"><x-ltr>{{ $deployment->finished_at->format('H:i') }}</x-ltr></span> — <span class="tl-label">{{ __('status.' . $deployment->status) }}</span></li>
        @endif
    </ul>
</x-card>
@endif

@if ($deployment->tasks->isNotEmpty())
<x-card :title="__('ui.deployments.targets_title', ['environment' => $deployment->project->environments->first()?->name ?? __('ui.common.environment')])">
    <table>
        <thead><tr>
            <th>{{ __('ui.deployments.target') }}</th>
            <th>{{ __('ui.common.server') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.deployments.agent') }}</th>
            <th>{{ __('ui.deployments.attempts') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @foreach ($deployment->tasks as $task)
            <tr>
                <td>{{ $task->deploymentTarget->name }}</td>
                <td><a href="{{ route('servers.show', $task->deploymentTarget->server) }}">{{ $task->deploymentTarget->server->name }}</a></td>
                <td>
                    <x-badge :status="$task->status" />
                    @if ($task->next_retry_at)
                        <span class="badge badge-queued">{{ __('ui.deployments.retry_at', ['time' => $task->next_retry_at->diffForHumans()]) }}</span>
                    @endif
                </td>
                <td>
                    @php $lastJob = $task->agentJobs->last(); @endphp
                    @if ($lastJob)
                        {{ $lastJob->agent->name ?? $lastJob->agent->public_id }}
                    @else
                        <x-empty-state>{{ __('ui.deployments.not_claimed_yet') }}</x-empty-state>
                    @endif
                </td>
                <td>{{ $task->agentJobs->count() }}</td>
                <td><a href="{{ route('deployment-tasks.show', $task) }}">{{ __('ui.common.details') }} ←</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-card>
@endif

<x-card :title="__('ui.deployments.pipeline_log_title')">
    @forelse ($deployment->logEntries as $entry)
        <h4 style="margin-bottom:0.2rem;">
            {{ $entry->sequence }}. {{ $entry->step_name }}
            {{-- Root-cause fix (sandogh deployment overhaul): a recipe step
            (explicit `status` column set) can fail with no exit_code at
            all — a verify_* check, or a restart_service the agent-local
            ServiceAllowlist rejected before ever spawning a process — so
            this can't rely on exit_code alone the way a legacy pipeline
            entry (status always null) always has. --}}
            @if ($entry->status !== null)
                <span class="badge badge-{{ $entry->status }}">{{ __('status.' . $entry->status) }}</span>
                @if ($entry->step_type)
                    <span class="badge">{{ __('ui.deployments.recipe_step_type', ['type' => $entry->step_type]) }}</span>
                @endif
                @if ($entry->failure_code)
                    <span class="muted">{{ __('ui.deployments.failure_code', ['code' => $entry->failure_code]) }}</span>
                @endif
            @elseif ($entry->exit_code === 0)
                <span class="badge badge-succeeded">{{ __('ui.deployment_tasks.exit_success') }}</span>
            @elseif ($entry->exit_code !== null)
                <span class="badge badge-failed">{{ __('ui.deployment_tasks.exit_failed', ['code' => $entry->exit_code]) }}</span>
            @endif
            @if ($entry->timed_out)
                <span class="badge badge-failed">{{ __('ui.deployment_tasks.timed_out') }}</span>
            @endif
            <span class="muted">(<x-ltr>{{ $entry->duration_ms }}ms</x-ltr>)</span>
        </h4>
        <p class="muted" style="margin-top:0;"><x-ltr><code>{{ $entry->command }}</code></x-ltr></p>
        <pre>{{ $entry->output }}</pre>
    @empty
        <x-empty-state>{{ __('ui.deployments.no_log_entries') }}</x-empty-state>
    @endforelse
</x-card>
@endsection
