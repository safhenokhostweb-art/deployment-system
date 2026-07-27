@extends('layouts.app')

@section('title', __('ui.local_agents.pending_title'))

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ __('ui.local_agents.pending_title') }}</h1>
    <a href="{{ route('local-agents.index') }}" class="btn">{{ __('ui.local_agents.all_button') }}</a>
</div>

@if (session('generated_registration_token'))
<x-card style="border-color:#a41e1e;" :title="__('ui.local_agents.registration_once_title')">
    <p class="muted">{{ __('ui.local_agents.registration_once_intro', ['minutes' => config('deployment.agent_registration_token_ttl_minutes')]) }}</p>
    <label>registration_id</label>
    <div class="secret-box">{{ session('generated_registration_id') }}</div>
    <label>registration_token</label>
    <div class="secret-box">{{ session('generated_registration_token') }}</div>
</x-card>
@endif

<x-card>
    <table>
        <thead><tr>
            <th>public_id</th>
            <th>{{ __('ui.local_agents.self_reported_name') }}</th>
            <th>{{ __('ui.local_agents.os') }}</th>
            <th>{{ __('ui.local_agents.capabilities') }}</th>
            <th>{{ __('ui.common.registered') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($pending as $localAgent)
            <tr>
                <td><a href="{{ route('local-agents.show', $localAgent) }}"><x-ltr><code>{{ $localAgent->public_id }}</code></x-ltr></a></td>
                <td>{{ $localAgent->name ?? __('ui.local_agents.not_registered_yet') }}</td>
                <td>{{ $localAgent->operating_system ?? '—' }}</td>
                <td class="muted">
                    @if ($localAgent->capabilities)
                        @foreach ($localAgent->capabilities as $key => $value)
                            <span class="badge badge-queued" style="margin:0 0.2rem 0.2rem 0;"><x-ltr>{{ $key }}: {{ is_bool($value) ? ($value ? __('status.yes') : __('status.no')) : (is_array($value) ? implode(',', $value) : $value) }}</x-ltr></span>
                        @endforeach
                    @else
                        —
                    @endif
                </td>
                <td>{{ $localAgent->created_at->diffForHumans() }}</td>
                <td style="white-space:nowrap;">
                    @can('local_agents.manage')
                    @if ($localAgent->name)
                        <form method="POST" action="{{ route('local-agents.approve', $localAgent) }}" style="display:inline">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-small">{{ __('ui.local_agents.approve_button') }}</button>
                        </form>
                        <form method="POST" action="{{ route('local-agents.reject', $localAgent) }}" id="reject-local-agent-{{ $localAgent->id }}-form" style="display:inline">
                            @csrf
                        </form>
                        <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('reject-local-agent-{{ $localAgent->id }}-dialog').showModal()">{{ __('ui.local_agents.reject_button') }}</button>
                        <x-confirm-dialog
                            id="reject-local-agent-{{ $localAgent->id }}-dialog"
                            form-id="reject-local-agent-{{ $localAgent->id }}-form"
                            :title="__('ui.local_agents.reject_button')"
                            :body="__('ui.local_agents.reject_confirm')"
                            :confirm-label="__('ui.local_agents.reject_button')"
                        />
                    @else
                        <span class="muted">{{ __('ui.local_agents.awaiting_registration') }}</span>
                        <form method="POST" action="{{ route('local-agents.reject', $localAgent) }}" id="discard-local-agent-{{ $localAgent->id }}-form" style="display:inline">
                            @csrf
                        </form>
                        <button type="button" class="btn btn-small" onclick="document.getElementById('discard-local-agent-{{ $localAgent->id }}-dialog').showModal()">{{ __('ui.local_agents.discard_button') }}</button>
                        <x-confirm-dialog
                            id="discard-local-agent-{{ $localAgent->id }}-dialog"
                            form-id="discard-local-agent-{{ $localAgent->id }}-form"
                            :title="__('ui.local_agents.discard_button')"
                            :body="__('ui.local_agents.discard_confirm')"
                            :confirm-label="__('ui.local_agents.discard_button')"
                            :danger="false"
                        />
                    @endif
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="6"><x-empty-state>{{ __('ui.local_agents.no_pending') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
