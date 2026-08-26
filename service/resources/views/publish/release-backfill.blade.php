@extends('layouts.app')

@section('title', 'Backfill release metadata')

@section('content')
<x-card title="Backfill release metadata">
    <p>Admin-only bootstrap action: records release metadata for a commit that was already pushed and built before the provider-neutral release contract had metadata for it. This never creates a Git commit and never pushes — commit, base SHA, and changed files below are re-derived live from GitHub, not editable.</p>

    <dl class="definition-list">
        <dt>Project</dt><dd>{{ $project->name ?? $project->project_key }}</dd>
        <dt>Branch</dt><dd dir="ltr">{{ $state['branch'] }}</dd>
        <dt>Commit</dt><dd dir="ltr">{{ $state['commit'] ?? '—' }}</dd>
        <dt>Base SHA</dt><dd dir="ltr">{{ $state['base_sha'] ?? '—' }}</dd>
        <dt>Artifact</dt><dd>{{ $state['artifact'] ? '#'.$state['artifact']->id.' ('.number_format((int) $state['artifact']->size_bytes).' bytes)' : '— none valid for this exact commit yet' }}</dd>
        <dt>Changed files ({{ count($state['changed_files']) }})</dt>
        <dd>
            @if(count($state['changed_files']))
                <details><summary>Review changes (GitHub-verified, not editable)</summary><ul>@foreach($state['changed_files'] as $file)<li dir="ltr">{{ $file }}</li>@endforeach</ul></details>
            @else
                —
            @endif
        </dd>
        <dt>Migration verdict</dt>
        <dd>{{ empty($state['migration_files']) ? 'No migrations in this commit' : count($state['migration_files']).' migration file(s) — must be explicitly reviewed below' }}</dd>
        <dt>Test evidence source</dt>
        <dd>Operator-confirmed (see checklist below) — clearly distinct from automated tool-run evidence; nothing here is auto-verified test output.</dd>
        <dt>Health contract</dt><dd>Required after deployment (server-managed-exact-commit)</dd>
        <dt>Current readiness</dt>
        <dd>{{ $state['release'] && ($state['release']->metadata['release_ready'] ?? false) ? 'READY' : 'NOT READY' }}</dd>
    </dl>

    @if(!empty($state['missing']))
        <p><strong>Missing requirements:</strong></p>
        <ul>
            @foreach($state['missing'] as $reason)
                <li class="alert alert-warning">{{ $reason }}</li>
            @endforeach
        </ul>
    @endif

    @if($state['can_submit'])
        <form method="POST" action="{{ route('projects.release-backfill.store', $project) }}">
            @csrf
            <p><strong>Operator-confirmed checks</strong> — only check what you have personally verified. Unchecked required items keep this release NOT READY; nothing here is inferred or assumed.</p>
            @foreach($state['required_checks'] as $key => $label)
                <label style="display:block;margin:0.4rem 0;">
                    <input type="checkbox" name="confirmed_checks[]" value="{{ $key }}">
                    {{ $label }}
                </label>
            @endforeach
            <label for="backfill-notes">Notes (optional, recorded as operator annotation only)</label>
            <textarea id="backfill-notes" name="notes" maxlength="2000" rows="3" style="width:100%;max-width:40rem;"></textarea>
            <label style="display:block;margin:0.6rem 0;">
                <input type="checkbox" name="confirm" value="1" required>
                I confirm the checks above are accurate and I am recording this release metadata deliberately.
            </label>
            <button class="btn btn-primary" type="submit">Record release metadata</button>
            <a class="btn" href="{{ route('projects.deploy-latest.show', $project) }}">Cancel</a>
        </form>
    @else
        <a class="btn" href="{{ route('projects.deploy-latest.show', $project) }}">Back to Deploy Latest</a>
    @endif
</x-card>
@endsection
