@extends('layouts.app')

@section('title', $agent->name ?? $agent->public_id)

@section('content')
<h1>{{ $agent->name ?? __('ui.agents.unregistered') }}</h1>
<p class="muted">{{ __('ui.agents.server_label') }}: <a href="{{ route('servers.show', $agent->server) }}">{{ $agent->server->name }}</a></p>

@if (session('generated_registration_token'))
<x-card style="border-color:#a41e1e;" :title="__('ui.agents.registration_once_title')">
    <p class="muted">{{ __('ui.agents.registration_once_intro', ['minutes' => config('deployment.agent_registration_token_ttl_minutes')]) }}</p>
    <label>registration_id</label>
    <div class="secret-box">{{ session('generated_registration_id') }}</div>
    <label>registration_token</label>
    <div class="secret-box">{{ session('generated_registration_token') }}</div>
</x-card>
@endif

@if (session('generated_token'))
<x-card style="border-color:#a41e1e;" :title="__('ui.agents.registration_once_title')">
    <p class="muted">{{ __('ui.agents.credentials_once_intro') }}</p>
    <label>agent public_id (server_id)</label>
    <div class="secret-box">{{ $agent->public_id }}</div>
    <label>agent_token</label>
    <div class="secret-box">{{ session('generated_token') }}</div>
    <label>signing_secret</label>
    <div class="secret-box">{{ session('generated_secret') }}</div>
</x-card>
@endif

<x-card>
    <table>
        <tr><th>public_id</th><td><x-ltr><code>{{ $agent->public_id }}</code></x-ltr></td></tr>
        <tr><th>{{ __('ui.common.status') }}</th><td>
            <x-badge :status="$agent->status" />
            &mdash;
            @if ($agent->isOnline())
                <x-badge status="online" />
            @else
                <x-badge status="offline" />
            @endif
        </td></tr>
        <tr><th>{{ __('ui.common.version') }}</th><td>{{ $agent->agent_version ?? __('ui.servers.not_reported_yet') }}</td></tr>
        <tr><th>{{ __('ui.agents.os') }}</th><td>{{ $agent->os ?? '—' }}</td></tr>
        <tr><th>{{ __('ui.agents.architecture') }}</th><td>{{ $agent->architecture ?? '—' }}</td></tr>
        <tr><th>{{ __('ui.agents.ip_address') }}</th><td><x-ltr>{{ $agent->ip_address ?? '—' }}</x-ltr></td></tr>
        <tr><th>{{ __('ui.agents.capabilities') }}</th><td>
            @if ($agent->capabilities)
                <pre>{{ json_encode($agent->capabilities, JSON_PRETTY_PRINT) }}</pre>
            @else
                <span class="muted">{{ __('ui.servers.not_reported_yet') }}</span>
            @endif
        </td></tr>
        <tr><th>{{ __('ui.common.last_heartbeat') }}</th><td>{{ $agent->last_seen_at?->diffForHumans() ?? __('status.never') }}</td></tr>
        <tr><th>{{ __('ui.common.registered') }}</th><td>{{ $agent->created_at->diffForHumans() }}</td></tr>
        @if ($agent->approved_by)
        <tr><th>{{ __('ui.agents.approved_by') }}</th><td>{{ $agent->approved_by }} &mdash; {{ $agent->approved_at?->diffForHumans() }}</td></tr>
        @endif
    </table>

    <div style="margin-top:1rem;display:flex;gap:0.6rem;">
        @if ($agent->status === 'pending_approval')
            @can('agents.approve')
            <form method="POST" action="{{ route('agents.approve', $agent) }}">
                @csrf
                <button type="submit" class="btn btn-primary" @if(!$agent->name) disabled title="{{ __('ui.agents.approve_disabled_title') }}" @endif>{{ __('ui.agents.approve_button') }}</button>
            </form>
            <form method="POST" action="{{ route('agents.reject', $agent) }}" id="reject-agent-form" style="display:inline">
                @csrf
            </form>
            <button type="button" class="btn btn-danger" onclick="document.getElementById('reject-agent-dialog').showModal()">{{ __('ui.agents.reject_button') }}</button>
            <x-confirm-dialog
                id="reject-agent-dialog"
                form-id="reject-agent-form"
                :title="__('ui.agents.reject_button')"
                :body="__('ui.agents.reject_confirm_pending')"
                :confirm-label="__('ui.agents.reject_button')"
            />
            @endcan
        @elseif ($agent->status === 'approved')
            @can('agents.disable')
            <form method="POST" action="{{ route('agents.disable', $agent) }}" id="disable-agent-form" style="display:inline">
                @csrf
            </form>
            <button type="button" class="btn btn-danger" onclick="document.getElementById('disable-agent-dialog').showModal()">{{ __('ui.agents.disable_button') }}</button>
            <x-confirm-dialog
                id="disable-agent-dialog"
                form-id="disable-agent-form"
                :title="__('ui.agents.disable_button')"
                :body="__('ui.agents.disable_confirm')"
                :confirm-label="__('ui.agents.disable_button')"
            />
            @endcan
        @elseif ($agent->status === 'disabled')
            @can('agents.disable')
            <form method="POST" action="{{ route('agents.reenable', $agent) }}">
                @csrf
                <button type="submit" class="btn">{{ __('ui.agents.reenable_button') }}</button>
            </form>
            @endcan
            @can('agents.delete')
            <form method="POST" action="{{ route('agents.destroy', $agent) }}" id="delete-agent-form" style="display:inline">
                @csrf
                @method('DELETE')
            </form>
            <button type="button" class="btn btn-danger" onclick="document.getElementById('delete-agent-dialog').showModal()">{{ __('ui.agents.delete_button') }}</button>
            <x-confirm-dialog
                id="delete-agent-dialog"
                form-id="delete-agent-form"
                :title="__('ui.agents.delete_button')"
                :body="__('ui.agents.delete_confirm')"
                :confirm-label="__('ui.agents.delete_button')"
            />
            @endcan
        @endif
    </div>
</x-card>
@endsection
