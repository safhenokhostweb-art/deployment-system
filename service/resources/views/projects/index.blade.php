@extends('layouts.app')

@section('title', 'Projects')

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>Projects</h1>
    <a href="{{ route('projects.create') }}" class="btn btn-primary">New project</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Repository</th><th>Branch</th><th>Environment</th><th>Server</th><th>Auto-deploy</th></tr></thead>
        <tbody>
        @forelse ($projects as $project)
            <tr>
                <td><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></td>
                <td>{{ $project->repository }}</td>
                <td>{{ $project->branch }}</td>
                <td>{{ $project->environment }}</td>
                <td>{{ $project->server->name }}</td>
                <td>{{ $project->auto_deploy ? 'yes' : 'no (requires approval)' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No projects yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
