@extends('layouts.app')

@section('title', __('ui.projects.title'))

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ __('ui.projects.title') }}</h1>
    @can('projects.create')
    <a href="{{ route('projects.create') }}" class="btn btn-primary">{{ __('ui.projects.new_button') }}</a>
    @endcan
</div>

<x-card>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }}</th>
            <th>{{ __('ui.common.repository') }}</th>
            <th>{{ __('ui.common.branch') }}</th>
            <th>{{ __('ui.common.environment') }}</th>
            <th>{{ __('ui.common.server') }}</th>
            <th>{{ __('ui.common.auto_deploy') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($projects as $project)
            <tr>
                <td><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></td>
                <td><x-ltr>{{ $project->repository }}</x-ltr></td>
                <td>{{ $project->branch }}</td>
                <td>{{ $project->environment }}</td>
                <td>{{ $project->server->name }}</td>
                <td>{{ $project->auto_deploy ? __('ui.projects.auto_deploy_yes') : __('ui.projects.auto_deploy_no') }}</td>
            </tr>
        @empty
            <tr><td colspan="6"><x-empty-state>{{ __('ui.projects.no_projects') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
