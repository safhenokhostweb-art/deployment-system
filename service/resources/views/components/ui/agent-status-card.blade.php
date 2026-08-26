@props(['agent'])
@php
    $displayStatus = $agent->status === \App\Models\Agent::STATUS_APPROVED
        ? ($agent->isOnline() ? 'online' : 'offline')
        : ($agent->status === \App\Models\Agent::STATUS_PENDING_APPROVAL ? 'pending' : 'offline');
@endphp
<article {{ $attributes->class(['ui-agent-card']) }}>
    <div class="ui-agent-card__heading">
        <a href="{{ route('agents.show', $agent) }}">{{ $agent->name ?? $agent->public_id }}</a>
        <x-ui.status-badge domain="agent" :status="$displayStatus" size="small" />
    </div>
    <dl>
        <div><dt>{{ __('dashboard_ui.server') }}</dt><dd>{{ $agent->server->name }}</dd></div>
        <div><dt>{{ __('dashboard_ui.version') }}</dt><dd class="ui-code-wrap">{{ $agent->agent_version ?? '—' }}</dd></div>
        <div><dt>{{ __('dashboard_ui.heartbeat') }}</dt><dd>{{ $agent->last_seen_at?->diffForHumans() ?? __('status.never') }}</dd></div>
    </dl>
</article>
