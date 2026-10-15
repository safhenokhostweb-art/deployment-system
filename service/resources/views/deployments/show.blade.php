@extends('layouts.app')

@section('title', __('deployment_ui.page_eyebrow') . ' ' . $deployment->public_id)

@section('content')
@php
    $firstTask = $deployment->tasks->first();
    $environmentName = $firstTask?->deploymentTarget?->environment?->name ?? $deployment->project->environment;
    $targetName = $deployment->tasks->count() > 1
        ? __('ui.dashboard.multiple_targets', ['count' => $deployment->tasks->count()])
        : ($firstTask?->deploymentTarget?->name ?? $deployment->server->name);
    $verdict = \App\Support\Ui\StatusRegistry::resolve('deployment', $deployment->status);
    $displayTime = fn ($time, $empty) => $time
        ? __('deployment_ui.exact_time', ['relative' => $time->diffForHumans(), 'exact' => $time->format('Y-m-d H:i:s T')])
        : $empty;
    $duration = $deployment->durationSeconds() !== null
        ? gmdate('H:i:s', $deployment->durationSeconds())
        : '—';

    $rollbackDeployment = \App\Models\Deployment::where('rollback_of_deployment_id', $deployment->id)->latest()->first();
    $failedLog = $deployment->logEntries->last(fn ($entry) => $entry->status === 'failed' || ($entry->exit_code !== null && $entry->exit_code !== 0) || $entry->timed_out);
    $failedTask = $deployment->tasks->first(fn ($task) => $task->status === 'failed');
    $failureCode = $deployment->failure_code ?? $failedTask?->failure_code ?? $failedLog?->failure_code;
    $failureMessage = $deployment->failure_message ?? $failedTask?->failure_message;
    $failedStage = $failedLog?->step_name ?? $failedTask?->deploymentTarget?->name ?? $currentStep['name'] ?? null;
    $guidance = $failureCode
        ? \App\Support\Deployment\FailureCodeGuidance::forCode($failureCode)
        : __('deployment_ui.generic_guidance');

    $artifactIntegrityFailed = in_array($failureCode, [
        \App\Models\Deployment::FAILURE_CODE_ARTIFACT_CHECKSUM_MISMATCH,
        \App\Models\Deployment::FAILURE_CODE_ARTIFACT_INTEGRITY_FAILED,
    ], true);
    $artifactUiStatus = null;
    if ($deployment->artifact) {
        $artifactUiStatus = match (true) {
            $artifactIntegrityFailed => 'failed',
            $deployment->artifact->expires_at?->isPast() => 'expired',
            in_array($deployment->artifact->status, ['uploading', 'available', 'failed'], true) => $deployment->artifact->status,
            default => 'missing',
        };
    } elseif ($deployment->artifact_id || $deployment->project->requires_complete_artifact) {
        $artifactUiStatus = 'missing';
    }

    $healthUiStatus = match (true) {
        ! $deployment->hasHttpHealthCheck() => 'not_configured',
        $deployment->http_health_status === \App\Models\Deployment::HTTP_HEALTH_VERIFIED => 'verified',
        $deployment->http_health_status === \App\Models\Deployment::HTTP_HEALTH_FAILED => 'failed',
        default => 'pending',
    };
    $healthDetails = $deployment->http_health_details ?? [];
    $healthConfig = $deployment->http_health_config ?? [];
@endphp

<x-ui.page-header
    :eyebrow="__('deployment_ui.page_eyebrow')"
    :title="$deployment->displayLabel() . ' — ' . $deployment->project->name"
    :description="__('deployment_ui.page_description', ['branch' => $deployment->branch, 'commit' => substr($deployment->commit_hash, 0, 12)])"
>
    <x-slot:actions>
        <a href="{{ route('projects.show', $deployment->project) }}" class="btn">{{ __('ui.common.project') }}: {{ $deployment->project->name }}</a>
    </x-slot:actions>
</x-ui.page-header>

<section class="ui-deployment-verdict ui-deployment-verdict--{{ $verdict['level'] }}" aria-labelledby="deployment-verdict-title">
    <div class="ui-deployment-verdict__summary">
        <x-ui.status-badge domain="deployment" :status="$deployment->status" />
        <h2 id="deployment-verdict-title">{{ __('deployment_ui.verdict.'.$deployment->status.'_title') }}</h2>
        <p>{{ __('deployment_ui.verdict.'.$deployment->status.'_description') }}</p>
    </div>
    <dl class="ui-deployment-verdict__facts">
        <div>
            <dt>{{ __('deployment_ui.deployment_id') }}</dt>
            <dd class="ui-code-wrap">{{ $deployment->public_id }}</dd>
        </div>
        <div>
            <dt>{{ __('deployment_ui.environment') }}</dt>
            <dd>{{ $environmentName }}</dd>
        </div>
        <div>
            <dt>{{ __('ui.deployments.started') }}</dt>
            <dd>{{ $displayTime($deployment->started_at, __('deployment_ui.not_started')) }}</dd>
        </div>
        <div>
            <dt>{{ __('ui.deployments.finished') }}</dt>
            <dd>{{ $displayTime($deployment->finished_at, __('deployment_ui.not_finished')) }}</dd>
        </div>
        <div>
            <dt>{{ __('deployment_ui.duration') }}</dt>
            <dd><x-ltr>{{ $duration }}</x-ltr></dd>
        </div>
        <div>
            <dt>{{ __('deployment_ui.target') }}</dt>
            <dd>{{ $targetName }}</dd>
        </div>
    </dl>
</section>

<div class="ui-action-bar" aria-label="{{ __('deployment_ui.actions') }}">
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
        <form method="POST" action="{{ route('deployments.retry', $deployment) }}" id="retry-deployment-form">
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
        <form method="POST" action="{{ route('deployments.rollback', $deployment) }}" id="rollback-deployment-form">
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
            <p>{{ __('ui.deployments.rollback_confirm_detailed', ['from' => $currentLive?->displayLabel() ?? '—', 'to' => $deployment->displayLabel()]) }}</p>
            <table style="margin-top:0.4rem;">
                <tr><th>{{ __('ui.deployments.rollback_from') }}</th><td><span class="release-tag">{{ $currentLive?->displayLabel() ?? '—' }}</span></td></tr>
                <tr><th>{{ __('ui.deployments.rollback_to') }}</th><td><span class="release-tag">{{ $deployment->displayLabel() }}</span></td></tr>
            </table>
        </x-confirm-dialog>
        @endcan
    @endif
</div>

<x-ui.info-card
    title="Sentinel deployment evidence checks"
    description="A successful result means only that the checks actually performed passed; it does not prove all application code or behavior is correct, approve Model B Gate 1, or change deployment status."
>
    <p><strong>Deployment status:</strong> {{ strtoupper((string) $deployment->status) }}</p>
    <p><strong>Evidence collection status:</strong>
        @if ($sentinelRun)
            RECORDED
        @else
            NOT COLLECTED
        @endif
    </p>
    @if ($sentinelRun)
        <p><strong>Overall verification verdict:</strong> {{ $sentinelRun->displayStatus() }} ({{ $sentinelRun->verdict }})</p>
        <p><strong>Collected:</strong> {{ $sentinelRun->collected_at?->format('Y-m-d H:i:s T') ?? 'Unknown' }}</p>
        <p><strong>Evidence SHA-256:</strong> <code class="ui-code-wrap">{{ $sentinelRun->evidence_hash ?? 'Unavailable' }}</code></p>
        @php
            $sentinelEvidence = $sentinelRun->evidence ?? [];
            $sentinelChecks = $sentinelRun->checks ?? [];
            $runtimeCheckKeys = ['agent_identity', 'runtime_marker_received', 'current_path_received', 'current_path_matches', 'deployed_release_matches', 'deployed_commit_matches', 'freshness', 'attribution'];
            $runtimeStatuses = collect($runtimeCheckKeys)->map(fn ($key) => $sentinelChecks[$key]['status'] ?? 'unknown');
            $runtimeConsistency = $runtimeStatuses->every(fn ($status) => $status === 'pass') ? 'PASS' : ($runtimeStatuses->contains('fail') ? 'FAIL' : 'INCONCLUSIVE');
            
            // File-level verification status
            $fileVerificationEnabled = config('sentinel.file_verification_enabled', false);
            $fileVerificationStatus = 'DISABLED';
            $fileVerificationDetail = '';
            if ($fileVerificationEnabled) {
                $fileVerificationStatus = strtoupper($sentinelChecks['file_manifest_hash_match']['status'] ?? 'unknown');
                $fileVerificationDetail = $sentinelChecks['file_manifest_hash_match']['detail'] ?? '';
            }
        @endphp
        <p><strong>Observed runtime consistency:</strong> {{ $runtimeConsistency }}</p>
        <p><strong>Independent health probe:</strong> {{ strtoupper($sentinelChecks['health_probe']['status'] ?? 'unknown') }}</p>
        @if ($fileVerificationEnabled)
            <p><strong>File-level verification:</strong> {{ $fileVerificationStatus }} — {{ $fileVerificationDetail }}</p>
        @else
            <p><strong>File-level verification:</strong> DISABLED (enable via sentinel.file_verification_enabled config)</p>
        @endif
        <dl class="ui-meta-grid">
            <div><dt>Expected / observed commit</dt><dd><code>{{ $sentinelEvidence['expected_commit_sha'] ?? 'Unknown' }}</code> / <code>{{ $sentinelEvidence['observed_commit_sha'] ?? 'Unknown' }}</code></dd></div>
            <div><dt>Artifact checksum: Hub record / bytes</dt><dd><code>{{ $sentinelEvidence['artifact_checksum_sha256'] ?? 'Unavailable' }}</code> / <code>{{ $sentinelEvidence['observed_artifact_checksum_sha256'] ?? 'Unavailable' }}</code></dd></div>
            <div><dt>Release: expected / runtime report</dt><dd><code>{{ $sentinelEvidence['release_id'] ?? 'Unknown' }}</code> / <code>{{ $sentinelEvidence['observed_release_id'] ?? 'Unknown' }}</code></dd></div>
            <div><dt>Runtime marker: expected / observed</dt><dd><code>{{ $sentinelEvidence['expected_runtime_marker'] ?? 'Unknown' }}</code> / <code>{{ $sentinelEvidence['runtime_marker'] ?? 'Unknown' }}</code></dd></div>
            <div><dt>Health status: expected / independent probe</dt><dd>{{ $sentinelEvidence['expected_health_status'] ?? 'Not configured' }} / {{ $sentinelEvidence['health_status'] ?? 'Unavailable' }}</dd></div>
            <div class="ui-meta-grid__full"><dt>Server Agent active release path</dt><dd class="ui-code-wrap">{{ $sentinelEvidence['runtime_release_path'] ?? 'Unavailable' }}</dd></div>
            @if ($fileVerificationEnabled)
                <div><dt>File manifest hash: expected / observed</dt><dd><code>{{ $sentinelEvidence['expected_file_manifest_hash'] ?? 'Unavailable' }}</code> / <code>{{ $sentinelEvidence['observed_file_manifest_hash'] ?? 'Unavailable' }}</code></dd></div>
                <div><dt>File manifest entries: expected / observed</dt><dd>{{ $sentinelEvidence['expected_file_manifest_entry_count'] ?? 'Unavailable' }} / {{ $sentinelEvidence['observed_file_manifest_entry_count'] ?? 'Unavailable' }}</dd></div>
                <div><dt>File manifest total size: expected / observed</dt><dd>{{ $sentinelEvidence['expected_file_manifest_total_size'] ?? 'Unavailable' }} / {{ $sentinelEvidence['observed_file_manifest_total_size'] ?? 'Unavailable' }} bytes</dd></div>
                <div><dt>File comparison scope</dt><dd>{{ $sentinelEvidence['file_comparison_scope'] ?? 'full' }}</dd></div>
            @endif
        </dl>
        
        @if ($fileVerificationEnabled && isset($sentinelChecks['file_comparison']))
            @php
                $fileComparison = $sentinelChecks['file_comparison'];
                $fileComparisonStatus = strtoupper($fileComparison['status'] ?? 'unknown');
                $fileComparisonDetail = $fileComparison['detail'] ?? 'No detail recorded.';
            @endphp
            <div class="ui-meta-grid" style="margin-top: var(--ui-space-4);">
                <div class="ui-meta-grid__full">
                    <dt>File Comparison Detail</dt>
                    <dd><strong>{{ $fileComparisonStatus }}</strong> — {{ $fileComparisonDetail }}</dd>
                </div>
            </div>
        @endif
        
        <dl class="ui-meta-grid">
            @foreach ($sentinelChecks as $check => $detail)
                @if (!in_array($check, ['file_comparison', 'file_manifest_hash_match', 'file_manifest_entry_count_match', 'file_manifest_total_size_match']))
                <div>
                    <dt>{{ str($check)->replace('_', ' ')->title() }}</dt>
                    <dd><strong>{{ strtoupper($detail['status'] ?? 'unknown') }}</strong> — {{ $detail['detail'] ?? 'No detail recorded.' }}</dd>
                </div>
                @endif
            @endforeach
        </dl>
        @if (! empty($sentinelRun->reasons))
            <ul>
                @foreach ($sentinelRun->reasons as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        @endif
    @endif
    @if ($deployment->release)
        <form method="POST" action="{{ route('deployments.sentinel.verify', $deployment) }}">
            @csrf
            <button type="submit" class="btn">Collect fresh read-only evidence</button>
        </form>
    @else
        <p>Fresh collection is unavailable because this deployment has no linked Release record.</p>
    @endif
</x-ui.info-card>

@if (in_array($deployment->status, ['failed', 'partial_success'], true))
<x-ui.alert-panel level="danger" :title="__('deployment_ui.failure_title')" class="ui-ops-grid__full" style="margin-bottom:var(--ui-space-5);">
    <dl class="ui-meta-grid" style="margin-top:var(--ui-space-3);">
        <div>
            <dt>{{ __('deployment_ui.failure_reason') }}</dt>
            <dd>
                @if ($failureCode)
                    <code class="ui-code-wrap">{{ $failureCode }}</code>
                @else
                    {{ __('deployment_ui.unknown_failure') }}
                @endif
            </dd>
        </div>
        <div>
            <dt>{{ __('deployment_ui.failed_stage') }}</dt>
            <dd>{{ $failedStage ?? __('deployment_ui.unknown_stage') }}</dd>
        </div>
        <div class="ui-meta-grid__full">
            <dt>{{ __('deployment_ui.error_message') }}</dt>
            <dd>{{ $failureMessage ?? __('deployment_ui.unknown_failure') }}</dd>
        </div>
        <div class="ui-meta-grid__full">
            <dt>{{ __('deployment_ui.operator_action') }}</dt>
            <dd>{{ $guidance }}</dd>
        </div>
    </dl>
</x-ui.alert-panel>
@endif

<div class="ui-ops-grid">
    <x-ui.info-card
        :title="__('deployment_ui.release_provenance')"
        :description="__('deployment_ui.release_provenance_description')"
    >
        <dl class="ui-meta-grid">
            <div class="ui-meta-grid__full">
                <dt>{{ __('deployment_ui.commit') }}</dt>
                <dd class="ui-code-wrap">{{ $deployment->commit_hash }}</dd>
            </div>
            <div>
                <dt>{{ __('deployment_ui.branch') }}</dt>
                <dd class="ui-code-wrap">{{ $deployment->branch }}</dd>
            </div>
            <div>
                <dt>{{ __('deployment_ui.trigger') }}</dt>
                <dd>
                    {{ $deployment->trigger }}
                    @if ($deployment->triggered_by)
                        — {{ $deployment->triggered_by }}
                    @endif
                </dd>
            </div>
            <div>
                <dt>{{ __('deployment_ui.artifact_id') }}</dt>
                <dd class="ui-code-wrap">{{ $deployment->artifact?->public_id ?? ($deployment->artifact_id ? '#'.$deployment->artifact_id : __('deployment_ui.artifact_not_used')) }}</dd>
            </div>
            <div>
                <dt>{{ __('deployment_ui.artifact_state') }}</dt>
                <dd>
                    @if ($artifactUiStatus)
                        <x-ui.status-badge domain="artifact" :status="$artifactUiStatus" />
                    @else
                        <span class="muted">{{ __('deployment_ui.artifact_not_used') }}</span>
                    @endif
                </dd>
            </div>
            <div class="ui-meta-grid__full">
                <dt>{{ __('deployment_ui.artifact_checksum') }}</dt>
                <dd>
                    @if ($deployment->artifact?->checksum_sha256)
                        <span class="ui-code-wrap">{{ $deployment->artifact->checksum_sha256 }}</span>
                        <span class="muted">— {{ $artifactIntegrityFailed ? __('deployment_ui.checksum_state_failed') : __('deployment_ui.checksum_state_recorded') }}</span>
                    @else
                        {{ __('deployment_ui.checksum_not_recorded') }}
                    @endif
                </dd>
            </div>
            @if ($deployment->artifact)
            <div>
                <dt>{{ __('deployment_ui.artifact_size') }}</dt>
                <dd><x-ltr>{{ $deployment->artifact->sizeMb() }} MB</x-ltr></dd>
            </div>
            @endif
            <div>
                <dt>{{ __('deployment_ui.build_source') }}</dt>
                <dd>{{ $deployment->artifact ? __('deployment_ui.build_source_artifact') : __('deployment_ui.build_source_direct') }}</dd>
            </div>
            <div>
                <dt>{{ __('deployment_ui.builder_agent') }}</dt>
                <dd>{{ $deployment->artifact?->uploaded_by ?? '—' }}</dd>
            </div>
            <div>
                <dt>{{ __('deployment_ui.approval') }}</dt>
                <dd>{{ $deployment->approved_by ? __('deployment_ui.approved_by_at', ['actor' => $deployment->approved_by, 'time' => $displayTime($deployment->approved_at, '—')]) : __('deployment_ui.not_approved') }}</dd>
            </div>
            @if ($deployment->previous_commit_hash)
            <div class="ui-meta-grid__full">
                <dt>{{ __('ui.deployments.previous_commit') }}</dt>
                <dd class="ui-code-wrap">{{ $deployment->previous_commit_hash }}</dd>
            </div>
            @endif
            @if ($deployment->rollbackOf || $rollbackDeployment)
            <div class="ui-meta-grid__full">
                <dt>{{ __('deployment_ui.rollback_lineage') }}</dt>
                <dd>
                    @if ($deployment->rollbackOf)
                        {{ __('ui.deployments.rollback_of') }}
                        <a href="{{ route('deployments.show', $deployment->rollbackOf) }}" class="ui-code-wrap">{{ $deployment->rollbackOf->public_id }}</a>
                    @endif
                    @if ($rollbackDeployment)
                        {{ __('ui.deployments.rolled_back_by') }}
                        <a href="{{ route('deployments.show', $rollbackDeployment) }}" class="ui-code-wrap">{{ $rollbackDeployment->public_id }}</a>
                        <x-ui.status-badge domain="deployment" :status="$rollbackDeployment->status" size="small" />
                    @endif
                </dd>
            </div>
            @endif
            @if ($deployment->release_notes)
            <div class="ui-meta-grid__full">
                <dt>{{ __('ui.deployments.release_notes_field') }}</dt>
                <dd>{{ $deployment->release_notes }}</dd>
            </div>
            @endif
        </dl>
    </x-ui.info-card>

    <x-ui.info-card
        :title="__('deployment_ui.health_title')"
        :description="__('deployment_ui.health_description')"
    >
        <x-ui.health-indicator
            :status="$healthUiStatus"
            :detail="$deployment->hasHttpHealthCheck() ? __('deployment_ui.health_configured') : __('deployment_ui.health_not_configured')"
        />
        <dl class="ui-meta-grid" style="margin-top:var(--ui-space-4);">
            <div class="ui-meta-grid__full">
                <dt>{{ __('deployment_ui.verified_live') }}</dt>
                <dd>
                    @if ($deployment->isVerifiedDeployed())
                        <span class="stat-good">{{ __('ui.deployments.verified_deployed_yes') }} — {{ __('deployment_ui.verified_live_yes') }}</span>
                    @else
                        <span class="stat-warn">{{ __('ui.deployments.verified_deployed_no') }} — {{ __('deployment_ui.verified_live_no') }}</span>
                    @endif
                </dd>
            </div>
            @if ($deployment->hasHttpHealthCheck())
            <div class="ui-meta-grid__full">
                <dt>{{ __('deployment_ui.health_url') }}</dt>
                <dd class="ui-code-wrap">{{ $healthConfig['url'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>{{ __('deployment_ui.health_expected_status') }}</dt>
                <dd><x-ltr>{{ $healthConfig['expected_status'] ?? 200 }}</x-ltr></dd>
            </div>
            <div>
                <dt>{{ __('deployment_ui.health_response_time') }}</dt>
                <dd><x-ltr>{{ isset($healthDetails['duration_ms']) ? $healthDetails['duration_ms'].' ms' : '—' }}</x-ltr></dd>
            </div>
            <div class="ui-meta-grid__full">
                <dt>{{ $healthUiStatus === 'failed' ? __('deployment_ui.health_failure_reason') : __('deployment_ui.health_result') }}</dt>
                <dd>{{ $healthDetails['output'] ?? ($healthUiStatus === 'failed' ? ($failureMessage ?? __('deployment_ui.health_no_result')) : __('deployment_ui.health_no_result')) }}</dd>
            </div>
            @endif
        </dl>
        @if ($deployment->http_health_verified_at)
            <div class="ui-health-result">{{ $displayTime($deployment->http_health_verified_at, '—') }}</div>
        @endif
    </x-ui.info-card>

    <x-ui.info-card
        class="ui-ops-grid__full"
        :title="__('deployment_ui.execution_timeline')"
        :description="__('deployment_ui.execution_timeline_description')"
    >
        @if (!empty($steps))
            <h3 style="font-size:var(--ui-text-sm);margin-top:0;">
                {{ $usesRecipe ? __('ui.deployments.recipe_steps_title') : __('ui.deployments.pipeline_steps_title') }}
            </h3>
            <div class="ui-stage-list">
                @foreach ($steps as $step)
                    <x-ui.status-badge domain="deployment" :status="$step['status']" :label="$step['name']" size="small" :title="__('status.'.$step['status'])" />
                @endforeach
            </div>
            @if ($currentStep)
                <p class="muted"><strong>{{ __('ui.deployments.current_step') }}:</strong> {{ $currentStep['name'] }}</p>
            @endif
        @endif

        @if ($deployment->approved_at || $deployment->queued_at || $deployment->started_at || collect($steps)->contains(fn ($step) => !in_array($step['status'], ['pending', 'skipped'], true)) || $deployment->finished_at)
        <x-ui.timeline :label="__('deployment_ui.execution_timeline')">
            @if ($deployment->approved_at)
            <li class="ui-timeline__item--success">
                <span class="ui-timeline__time">{{ $displayTime($deployment->approved_at, '—') }}</span>
                <span class="ui-timeline__event"><span class="ui-timeline__title">{{ __('deployment_ui.event_approved') }}</span></span>
            </li>
            @endif
            @if ($deployment->queued_at)
            <li class="ui-timeline__item--success">
                <span class="ui-timeline__time">{{ $displayTime($deployment->queued_at, '—') }}</span>
                <span class="ui-timeline__event"><span class="ui-timeline__title">{{ __('deployment_ui.event_queued') }}</span></span>
            </li>
            @endif
            @if ($deployment->started_at)
            <li class="ui-timeline__item--info">
                <span class="ui-timeline__time">{{ $displayTime($deployment->started_at, '—') }}</span>
                <span class="ui-timeline__event"><span class="ui-timeline__title">{{ __('deployment_ui.event_started') }}</span></span>
            </li>
            @endif
            @foreach ($steps as $step)
                @continue(in_array($step['status'], ['pending', 'skipped'], true))
                @php
                    $stepPresentation = \App\Support\Ui\StatusRegistry::resolve('deployment', $step['status']);
                @endphp
                <li class="ui-timeline__item--{{ $stepPresentation['level'] }}">
                    <span class="ui-timeline__time">{{ $step['started_at'] ? $displayTime($step['started_at'], '—') : '—' }}</span>
                    <span class="ui-timeline__event">
                        <span class="ui-timeline__title">{{ $step['name'] }}</span>
                        <x-ui.status-badge domain="deployment" :status="$step['status']" size="small" />
                        @if ($step['duration_ms'] !== null)
                            <span class="muted ui-code-wrap">{{ $step['duration_ms'] }} ms</span>
                        @endif
                    </span>
                </li>
            @endforeach
            @if ($deployment->http_health_verified_at)
            <li class="ui-timeline__item--success">
                <span class="ui-timeline__time">{{ $displayTime($deployment->http_health_verified_at, '—') }}</span>
                <span class="ui-timeline__event"><span class="ui-timeline__title">{{ __('deployment_ui.event_health_verified') }}</span></span>
            </li>
            @endif
            @if ($deployment->rolled_back_at)
            <li class="ui-timeline__item--warning">
                <span class="ui-timeline__time">{{ $displayTime($deployment->rolled_back_at, '—') }}</span>
                <span class="ui-timeline__event"><span class="ui-timeline__title">{{ __('deployment_ui.event_rolled_back') }}</span></span>
            </li>
            @endif
            @if ($deployment->finished_at)
            <li class="ui-timeline__item--{{ $verdict['level'] }}">
                <span class="ui-timeline__time">{{ $displayTime($deployment->finished_at, '—') }}</span>
                <span class="ui-timeline__event">
                    <span class="ui-timeline__title">{{ __('deployment_ui.event_finished') }}</span>
                    <x-ui.status-badge domain="deployment" :status="$deployment->status" size="small" />
                </span>
            </li>
            @endif
        </x-ui.timeline>
        @else
            <x-ui.empty-state :title="__('deployment_ui.no_timeline_events')" />
        @endif
    </x-ui.info-card>

    <x-ui.info-card
        class="ui-ops-grid__full"
        :title="__('deployment_ui.targets_title')"
        :description="__('deployment_ui.targets_description')"
    >
        <div class="ui-table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('deployment_ui.target') }}</th>
                    <th>{{ __('deployment_ui.server') }}</th>
                    <th>{{ __('ui.common.status') }}</th>
                    <th>{{ __('deployment_ui.agent') }}</th>
                    <th>{{ __('deployment_ui.agent_version') }}</th>
                    <th>{{ __('deployment_ui.heartbeat') }}</th>
                    <th>{{ __('deployment_ui.attempts') }}</th>
                    <th></th>
                </tr></thead>
                <tbody>
                @forelse ($deployment->tasks as $task)
                    @php
                        $lastJob = $task->agentJobs->last();
                        $agent = $lastJob?->agent;
                    @endphp
                    <tr>
                        <td>{{ $task->deploymentTarget->name }}</td>
                        <td><a href="{{ route('servers.show', $task->deploymentTarget->server) }}">{{ $task->deploymentTarget->server->name }}</a></td>
                        <td><x-ui.status-badge domain="deployment" :status="$task->status" size="small" /></td>
                        <td>
                            @if ($agent)
                                <span class="ui-agent-identity">
                                    <span>{{ $agent->name ?? $agent->public_id }}</span>
                                    <x-ui.status-badge domain="agent" :status="$agent->isOnline() ? 'online' : 'offline'" size="small" />
                                </span>
                            @else
                                <span class="muted">{{ __('deployment_ui.agent_not_claimed') }}</span>
                            @endif
                        </td>
                        <td>{{ $agent?->agent_version ?? '—' }}</td>
                        <td>{{ $agent?->last_seen_at ? $displayTime($agent->last_seen_at, '—') : '—' }}</td>
                        <td>{{ $task->agentJobs->count() }}</td>
                        <td><a href="{{ route('deployment-tasks.show', $task) }}">{{ __('deployment_ui.view_target') }}</a></td>
                    </tr>
                @empty
                    <tr>
                        <td>{{ __('deployment_ui.legacy_target') }}</td>
                        <td><a href="{{ route('servers.show', $deployment->server) }}">{{ $deployment->server->name }}</a></td>
                        <td><x-ui.status-badge domain="deployment" :status="$deployment->status" size="small" /></td>
                        <td colspan="3"><span class="muted">{{ __('deployment_ui.agent_not_recorded') }}</span></td>
                        <td>—</td>
                        <td></td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.info-card>

    <x-ui.info-card
        class="ui-ops-grid__full"
        :title="__('deployment_ui.logs_title')"
        :description="__('deployment_ui.logs_description')"
    >
        <div class="ui-log-list">
            @forelse ($deployment->logEntries as $entry)
                @php
                    $entryStatus = $entry->status
                        ?? ($entry->exit_code === 0 ? 'succeeded' : ($entry->exit_code !== null ? 'failed' : 'running'));
                @endphp
                <details class="ui-log-entry">
                    <summary>
                        <span class="ui-log-entry__name">{{ $entry->sequence }}. {{ $entry->step_name }}</span>
                        <x-ui.status-badge domain="deployment" :status="$entryStatus" size="small" />
                        @if ($entry->timed_out)
                            <span class="ui-status ui-status--danger ui-status--small">{{ __('ui.deployment_tasks.timed_out') }}</span>
                        @endif
                        @if ($entry->failure_code)
                            <span class="muted ui-code-wrap">{{ $entry->failure_code }}</span>
                        @endif
                        <span class="muted ui-code-wrap">{{ $entry->duration_ms }} ms</span>
                    </summary>
                    <div class="ui-log-entry__content">
                        <strong>{{ __('deployment_ui.log_command') }}</strong>
                        <p class="ui-code-wrap">{{ $entry->command }}</p>
                        <strong>{{ __('deployment_ui.log_output') }}</strong>
                        <pre>{{ $entry->output }}</pre>
                    </div>
                </details>
            @empty
                <x-ui.empty-state :title="__('ui.deployments.no_log_entries')" />
            @endforelse
        </div>
    </x-ui.info-card>
</div>
@endsection
