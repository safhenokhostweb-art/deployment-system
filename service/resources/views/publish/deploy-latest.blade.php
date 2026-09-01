@extends('layouts.app')

@section('title', 'Deploy latest release')

@section('content')
<x-card title="Deploy latest release">
    <p>Review the release below before creating a deployment.</p>
    <p><strong>Release readiness:</strong> {{ $readiness['code'] }}</p>
    <p>{{ $readiness['message'] }}</p>
    @if($release && is_array($release->metadata))
        @php($metadata = $release->metadata)
        <dl class="definition-list">
            <dt>Release summary</dt><dd>{{ $metadata['summary'] ?? '—' }}</dd>
            <dt>Files changed</dt><dd>{{ count($metadata['changed_files'] ?? []) }}</dd>
            <dt>Build verification</dt><dd>{{ $verification['label'] }}</dd>
            <dt>Migration verdict</dt><dd>{{ empty($metadata['migration_files']) ? 'No migrations' : 'Safe by project policy' }}</dd>
            <dt>Risk flags</dt><dd>{{ empty($metadata['risk_flags']) ? 'None' : implode(', ', $metadata['risk_flags']) }}</dd>
            <dt>Health contract</dt><dd>{{ ($metadata['health_contract']['required'] ?? false) ? 'Required after deployment' : 'Not required' }}</dd>
        </dl>
        @if(!empty($metadata['changed_files']))
            <details><summary>Review changes</summary><ul>@foreach($metadata['changed_files'] as $file)<li dir="ltr">{{ $file }}</li>@endforeach</ul></details>
        @endif
    @endif
    @if($artifact && $readiness['deployable'])
        <dl class="definition-list">
            <dt>Project</dt><dd>{{ $project->name ?? $project->project_key }}</dd>
            <dt>Branch</dt><dd dir="ltr">{{ $artifact->branch }}</dd>
            <dt>Commit</dt><dd dir="ltr">{{ $artifact->commit_hash }}</dd>
            <dt>Artifact</dt><dd>#{{ $artifact->id }} ({{ number_format((int) $artifact->size_bytes) }} bytes)</dd>
            <dt>Checksum</dt><dd dir="ltr">{{ $artifact->checksum_sha256 }}</dd>
        </dl>
        <form method="POST" action="{{ route('projects.deploy-latest', $project) }}">
            @csrf
            <button class="btn btn-primary" type="submit">Create deployment candidate</button>
            <a class="btn" href="{{ route('projects.show', $project) }}">Cancel</a>
        </form>
    @else
        <p class="alert alert-warning">{{ $readiness['code'] }}{{ $latestRemoteCommit ? ' — '.$latestRemoteCommit : '' }}</p>
        <a class="btn" href="{{ route('projects.show', $project) }}">Back to project</a>
        @can('releases.backfill')
            @if($artifact)
                <a class="btn" href="{{ route('projects.release-backfill.show', $project) }}">Backfill release metadata</a>
            @endif
        @endcan
    @endif
</x-card>
@endsection
