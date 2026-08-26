@extends('layouts.app')

@section('title', 'Deploy latest release')

@section('content')
<x-card title="Deploy latest release">
    <p>Review the release below before creating a deployment.</p>
    @if($artifact)
        <dl class="definition-list">
            <dt>Project</dt><dd>{{ $project->name ?? $project->project_key }}</dd>
            <dt>Branch</dt><dd dir="ltr">{{ $artifact->branch }}</dd>
            <dt>Commit</dt><dd dir="ltr">{{ $artifact->commit_hash }}</dd>
            <dt>Artifact</dt><dd>#{{ $artifact->id }} ({{ number_format((int) $artifact->size_bytes) }} bytes)</dd>
            <dt>Checksum</dt><dd dir="ltr">{{ $artifact->checksum_sha256 }}</dd>
        </dl>
        <form method="POST" action="{{ route('projects.deploy-latest', $project) }}">
            @csrf
            <button class="btn btn-primary" type="submit">Confirm deployment</button>
            <a class="btn" href="{{ route('projects.show', $project) }}">Cancel</a>
        </form>
    @else
        <p class="alert alert-warning">No physically valid available Artifact exists for the latest remote production-branch commit{{ $latestRemoteCommit ? ' '.$latestRemoteCommit : '' }}. Build an Artifact first.</p>
        <a class="btn" href="{{ route('projects.show', $project) }}">Back to project</a>
    @endif
</x-card>
@endsection
