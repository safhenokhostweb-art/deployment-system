@extends('layouts.app')

@section('title', __('ui.operations.title'))

@section('content')
<h1>{{ __('ui.operations.title') }}</h1>
<p class="muted" style="margin-top:-0.6rem;">{{ __('ui.operations.intro') }}</p>

@forelse ($servers as $row)
    @php
        $server = $row['server'];
        $agent = $row['agent'];
        $project = $row['project'];
        $latest = $row['latest'];
    @endphp
    <x-card :title="$server->name">
        <p class="muted" style="margin-top:0;">
            <x-badge status="{{ $server->isOnline() ? 'online' : 'offline' }}" />
            @if ($project)
                — {{ $project->name }}
            @endif
        </p>

        @if ($latest)
            <p class="muted" style="margin:0 0 0.6rem;">
                {{ __('ui.operations.latest_operation_label') }}:
                {{ __('ui.operations.type_' . $latest->operation_type) }}
                <x-badge :status="$latest->status" />
                {{ $latest->created_at->diffForHumans() }}
                <a href="{{ route('operations.show', $latest) }}">{{ __('ui.operations.view_details_button') }}</a>
            </p>
        @endif

        @can('operations.execute')
            @if ($agent)
                <div class="pipeline-steps">
                    @foreach ($serverTypes as $type)
                        <form method="POST" action="{{ route('operations.store', $server) }}" style="display:inline">
                            @csrf
                            <input type="hidden" name="operation_type" value="{{ $type }}">
                            <button type="submit" class="btn btn-small">{{ __('ui.operations.type_' . $type) }}</button>
                        </form>
                    @endforeach
                    @if ($project)
                        @foreach ($laravelTypes as $type)
                            <form method="POST" action="{{ route('operations.store', $server) }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="operation_type" value="{{ $type }}">
                                <button type="submit" class="btn btn-small">{{ __('ui.operations.type_' . $type) }}</button>
                            </form>
                        @endforeach
                    @endif
                </div>
                @unless ($project)
                    <p class="muted" style="margin-top:0.6rem;">{{ __('ui.operations.no_project_notice') }}</p>
                @endunless
            @else
                <x-alert type="error">{{ __('ui.operations.no_agent_notice') }}</x-alert>
            @endif
        @endcan
    </x-card>
@empty
    <x-card>
        <x-empty-state>{{ __('ui.operations.no_servers') }}</x-empty-state>
    </x-card>
@endforelse
@endsection
