@extends('layouts.app')

@section('title', __('ui.publish.title'))

@section('content')
<h1>{{ __('ui.publish.title') }} — {{ $project->name }}</h1>
<p class="muted">
    {{ __('ui.common.action') }}: {{ __('ui.publish.action_' . $publishJob->action) }}
    &mdash;
    <x-badge :status="$publishJob->status" />
</p>

<x-card>
    @if ($publishJob->action === 'status_check' && $publishJob->status === 'awaiting_approval')
        <h3>{{ __('ui.publish.changed_files_title') }}</h3>
        <p>{{ __('ui.publish.files_changed_count', ['count' => $publishJob->files_changed_count]) }}</p>

        @if ($publishJob->files_changed_count === 0)
            <p class="muted">{{ __('ui.publish.no_changes') }}</p>
            <p class="muted">No source changes were found. Confirming will build and upload an artifact for the verified current commit; it will not create a commit or push.</p>
            @can('local_agents.publish')
            <form method="POST" action="{{ route('projects.publish.approve', [$project, $publishJob]) }}" style="margin-top:1rem;">
                @csrf
                <button type="submit" class="btn btn-primary">Confirm publish existing commit</button>
            </form>
            @endcan
        @else
            <ul>
                @foreach ($publishJob->changed_files as $file)
                    <li><x-ltr><code>{{ $file }}</code></x-ltr></li>
                @endforeach
            </ul>

            @if (!empty($publishJob->blocked_files))
            <h3>{{ __('ui.publish.blocked_files_title') }}</h3>
            <p class="muted">{{ __('ui.publish.blocked_files_hint') }}</p>
            <ul>
                @foreach ($publishJob->blocked_files as $file)
                    <li><x-ltr><code>{{ $file }}</code></x-ltr></li>
                @endforeach
            </ul>
            @endif

            @can('local_agents.publish')
            <form method="POST" action="{{ route('projects.publish.approve', [$project, $publishJob]) }}" style="margin-top:1rem;">
                @csrf
                <label for="commit_message">{{ __('ui.publish.commit_message_label') }}</label>
                <input type="text" id="commit_message" name="commit_message" required maxlength="500" style="width:100%;">
                <button type="submit" class="btn btn-primary" style="margin-top:0.6rem;">{{ __('ui.publish.approve_publish_button') }}</button>
            </form>
            @endcan
        @endif
    @elseif ($publishJob->status === 'running' && $publishJob->isLeaseExpired())
        {{-- Root-cause fix, section 8: never shown as a plain "در حال
             اجرا" once the lease has expired but deployments:recover-stale
             hasn't swept it yet — a stuck job must never look identical to
             a healthy one, even for the one-minute window before the next
             scheduled sweep. --}}
        <p><x-badge status="awaiting_recovery" /></p>
        <p class="muted">{{ __('ui.publish.lease_expired_awaiting_recovery') }}</p>
        <p class="muted">{{ __('ui.publish.job_last_heartbeat', ['time' => $publishJob->heartbeat_at?->diffForHumans() ?? __('status.never')]) }}</p>
        <p class="muted">{{ __('ui.publish.lease_expired_at', ['time' => $publishJob->lease_expires_at?->diffForHumans() ?? __('status.never')]) }}</p>
    @elseif (in_array($publishJob->status, ['queued', 'approved', 'running']))
        @if (!$publishJob->localAgent->isOnline())
            <p class="muted">{{ __('ui.publish.agent_offline_waiting') }}</p>
            <p class="muted">
                {{ __('ui.publish.agent_last_heartbeat', ['time' => $publishJob->localAgent->last_seen_at?->diffForHumans() ?? __('status.never')]) }}
                — <a href="{{ route('local-agents.show', $publishJob->localAgent) }}">{{ __('ui.publish.agent_offline_help_link') }}</a>
            </p>
        @else
            <p class="muted">{{ __('ui.publish.waiting_for_agent') }}</p>
        @endif
        @if ($publishJob->status === 'running')
            <p class="muted">{{ __('ui.publish.running_since', ['seconds' => $publishJob->durationSeconds()]) }}</p>
            <p class="muted">{{ __('ui.publish.job_last_heartbeat', ['time' => $publishJob->heartbeat_at?->diffForHumans() ?? __('status.never')]) }}</p>
            <p class="muted">{{ __('ui.publish.executing_agent', ['agent' => $publishJob->localAgent->name ?? $publishJob->localAgent->public_id]) }}</p>
            @if ($publishJob->lease_expires_at)
                <p class="muted">{{ __('ui.publish.lease_expires_at', ['time' => $publishJob->lease_expires_at->diffForHumans()]) }}</p>
            @endif
        @endif
    @elseif ($publishJob->status === 'succeeded')
        @if ($publishJob->result_commit_hash)
            <p>{{ __('ui.publish.result_commit_hash') }}: <x-ltr><code>{{ $publishJob->result_commit_hash }}</code></x-ltr></p>
        @endif
        @if ($publishJob->output)
            <h3>{{ __('ui.publish.output_label') }}</h3>
            <pre>{{ $publishJob->output }}</pre>
        @endif

        <h3>{{ __('ui.publish.deployment_status_title') }}</h3>
        @if ($resultingDeployment)
            <p>
                <x-badge :status="$resultingDeployment->status" />
                <a href="{{ route('deployments.show', $resultingDeployment) }}">{{ __('ui.publish.view_deployment_button') }}</a>
            </p>
        @else
            <p class="muted">{{ __('ui.publish.deployment_awaiting_webhook') }}</p>
        @endif
    @elseif (in_array($publishJob->status, ['failed', 'timed_out', 'abandoned']))
        <h3>{{ __('ui.publish.error_label') }}</h3>
        <p class="muted">{{ $publishJob->error_message }}</p>
        @if ($publishJob->status === 'abandoned')
            {{-- Root-cause fix, section 8: distinguishes "the agent told us
                 it failed/timed out" from "the Hub never heard back at all
                 and inferred this" — an operator reading only error_message
                 could otherwise mistake one for the other. --}}
            <p class="muted">{{ __('ui.publish.abandoned_explanation') }}</p>
        @endif
        @if ($publishJob->output)
            <pre>{{ $publishJob->output }}</pre>
        @endif

        @if ($publishJob->action === 'publish')
        @can('local_agents.publish')
        <form method="POST" action="{{ route('projects.publish.retry', [$project, $publishJob]) }}" style="margin-top:1rem;">
            @csrf
            <button type="submit" class="btn btn-primary">{{ __('ui.publish.retry_button') }}</button>
        </form>
        @endcan
        @endif
    @endif
</x-card>
@endsection
