@extends('layouts.app')

@section('title', __('ui.agents.pending_title'))

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ __('ui.agents.pending_title') }}</h1>
    <a href="{{ route('agents.index') }}" class="btn">{{ __('ui.agents.all_agents_button') }}</a>
</div>

<x-card>
    <table>
        <thead><tr>
            <th>public_id</th>
            <th>{{ __('ui.common.server') }}</th>
            <th>{{ __('ui.agents.self_reported_name') }}</th>
            <th>{{ __('ui.agents.os') }}</th>
            <th>{{ __('ui.agents.architecture') }}</th>
            <th>{{ __('ui.common.version') }}</th>
            <th>{{ __('ui.agents.capabilities') }}</th>
            <th>{{ __('ui.common.registered') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($pending as $agent)
            <tr>
                <td><a href="{{ route('agents.show', $agent) }}"><x-ltr><code>{{ $agent->public_id }}</code></x-ltr></a></td>
                <td><a href="{{ route('servers.show', $agent->server) }}">{{ $agent->server->name }}</a></td>
                <td>{{ $agent->name ?? __('ui.agents.not_registered_yet') }}</td>
                <td>{{ $agent->os ?? '—' }}</td>
                <td>{{ $agent->architecture ?? '—' }}</td>
                <td>{{ $agent->agent_version ?? '—' }}</td>
                <td class="muted">
                    @if ($agent->capabilities)
                        @foreach ($agent->capabilities as $key => $value)
                            <span class="badge badge-queued" style="margin:0 0.2rem 0.2rem 0;"><x-ltr>{{ $key }}: {{ is_bool($value) ? ($value ? __('status.yes') : __('status.no')) : (is_array($value) ? implode(',', $value) : $value) }}</x-ltr></span>
                        @endforeach
                    @else
                        —
                    @endif
                </td>
                <td>{{ $agent->created_at->diffForHumans() }}</td>
                <td style="white-space:nowrap;">
                    @can('agents.approve')
                    @if ($agent->name)
                        <form method="POST" action="{{ route('agents.approve', $agent) }}" style="display:inline">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-small">{{ __('ui.agents.approve_button') }}</button>
                        </form>
                        <form method="POST" action="{{ route('agents.reject', $agent) }}" id="reject-agent-{{ $agent->id }}-form" style="display:inline">
                            @csrf
                        </form>
                        <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('reject-agent-{{ $agent->id }}-dialog').showModal()">{{ __('ui.agents.reject_button') }}</button>
                        <x-confirm-dialog
                            id="reject-agent-{{ $agent->id }}-dialog"
                            form-id="reject-agent-{{ $agent->id }}-form"
                            :title="__('ui.agents.reject_button')"
                            :body="__('ui.agents.reject_confirm')"
                            :confirm-label="__('ui.agents.reject_button')"
                        />
                    @else
                        <span class="muted">{{ __('ui.agents.awaiting_registration') }}</span>
                        <form method="POST" action="{{ route('agents.reject', $agent) }}" id="discard-agent-{{ $agent->id }}-form" style="display:inline">
                            @csrf
                        </form>
                        <button type="button" class="btn btn-small" onclick="document.getElementById('discard-agent-{{ $agent->id }}-dialog').showModal()">{{ __('ui.agents.discard_button') }}</button>
                        <x-confirm-dialog
                            id="discard-agent-{{ $agent->id }}-dialog"
                            form-id="discard-agent-{{ $agent->id }}-form"
                            :title="__('ui.agents.discard_button')"
                            :body="__('ui.agents.discard_confirm')"
                            :confirm-label="__('ui.agents.discard_button')"
                            :danger="false"
                        />
                    @endif
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="9"><x-empty-state>{{ __('ui.agents.no_pending') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
