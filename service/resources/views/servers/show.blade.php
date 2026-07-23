@extends('layouts.app')

@section('title', $server->name)

@section('content')
<h1>{{ $server->name }}</h1>
<p class="muted">{{ $server->description }}</p>

{{-- Phase 4-L Task 3: Server Setup Wizard — a persistent step tracker,
     always shown (not just once right after registration), so coming
     back to this page later still shows exactly where setup stands. --}}
@unless ($server->isOnline())
<x-wizard-steps :current="$setupStep" :labels="[
    __('ui.servers.wizard_step1'),
    __('ui.servers.wizard_step2'),
    __('ui.servers.wizard_step3'),
    __('ui.servers.wizard_step4'),
    __('ui.servers.wizard_step5'),
]" />
@endunless

@if ($agentConfigContent)
    {{-- This box only exists on the one page load right after
         creation/rotation — everything in it comes from the same
         one-time session-flashed values, nothing new is stored or
         retrievable later. --}}
    <x-card style="border-color:#a41e1e;" :title="__('ui.servers.registration_created_title')">
        <p class="muted">{{ __('ui.servers.registration_created_intro') }}</p>
        <label>server_id</label>
        <div class="secret-box">{{ $server->public_id }}</div>
        <label>agent_token</label>
        <div class="secret-box">{{ session('generated_token') }}</div>
        <label>signing_secret</label>
        <div class="secret-box">{{ session('generated_secret') }}</div>

        <button type="button" class="btn btn-primary" style="margin-top:0.8rem;" onclick="downloadAgentConfig()">{{ __('ui.servers.download_config_button') }}</button>
        <pre id="agent-config-content" style="display:none;">{{ $agentConfigContent }}</pre>
        <script>
            function downloadAgentConfig() {
                var content = document.getElementById('agent-config-content').textContent;
                var blob = new Blob([content], { type: 'text/plain' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'config.php';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }
        </script>
    </x-card>

    @can('servers.rotate-credentials')
    @if ($oneClickInstallerContent)
    <x-card style="border-color:#a41e1e;" :title="__('ui.servers.one_click_title')">
        <p class="muted">{{ __('ui.servers.one_click_intro') }}</p>

        <button type="button" class="btn btn-primary" onclick="downloadOneClickInstaller()">{{ __('ui.servers.one_click_download_button') }}</button>
        <pre id="one-click-installer-content" style="display:none;">{{ $oneClickInstallerContent }}</pre>
        <script>
            function downloadOneClickInstaller() {
                var content = document.getElementById('one-click-installer-content').textContent;
                var blob = new Blob([content], { type: 'text/x-shellscript' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'install-agent.sh';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }
        </script>

        <ol style="margin-top:0.8rem;padding-inline-start:1.2rem;">
            <li><strong>{{ __('ui.servers.one_click_step1_title') }}</strong> — {{ __('ui.servers.one_click_step1_body') }}</li>
            <li><strong>{{ __('ui.servers.one_click_step2_title') }}</strong> — {{ __('ui.servers.one_click_step2_body') }}
                <x-ltr><pre dir="ltr" style="margin-top:0.4rem;">chmod +x install-agent.sh
sudo ./install-agent.sh</pre></x-ltr>
            </li>
            <li><strong>{{ __('ui.servers.one_click_step3_title') }}</strong> — {{ __('ui.servers.one_click_step3_body') }}</li>
        </ol>
        <p class="muted" style="margin-top:0.6rem;">{{ __('ui.servers.one_click_note') }}</p>
    </x-card>
    @endif
    @endcan
@elseif ($server->last_seen_at === null)
    <x-alert type="error">
        <strong>{{ __('ui.servers.not_connected_reminder_title') }}</strong>
        <p style="margin:0.3rem 0 0;">{{ __('ui.servers.not_connected_reminder_body') }}</p>
        @can('servers.rotate-credentials')
        <p class="muted" style="margin:0.4rem 0 0;">{{ __('ui.servers.lost_config_hint') }}</p>
        @endcan
    </x-alert>
@endif

@unless ($server->isOnline())
<x-card :title="__('ui.servers.install_instructions_title')">
    <ol class="install-steps">
        <li>{{ __('ui.servers.install_step_1') }}</li>
        <li>{{ __('ui.servers.install_step_2') }}</li>
        <li>{{ __('ui.servers.install_step_3') }}</li>
        <li>{{ __('ui.servers.install_step_4') }}</li>
        <li>{{ __('ui.servers.install_step_5') }}</li>
    </ol>

    {{-- Task 3: "give a ready install command" — a real, copy-paste
         systemd install sequence, not just prose. The only manual edit
         left is the absolute path, which only the operator's own server
         layout can supply. --}}
    <h4 style="margin-top:1.2rem;margin-bottom:0.4rem;">{{ __('ui.servers.ready_command_title') }}</h4>
    <p class="muted">{{ __('ui.servers.ready_command_intro') }}</p>
    <button type="button" class="btn btn-small" onclick="downloadAgentService()">{{ __('ui.servers.download_service_button') }}</button>
    <pre id="agent-service-content" style="display:none;">{{ $agentServiceFileContent }}</pre>
    <pre style="margin-top:0.6rem;" dir="ltr"><code>sudo mv deployment-agent.service /etc/systemd/system/deployment-agent.service
sudo systemctl daemon-reload
sudo systemctl enable --now deployment-agent</code></pre>
    <script>
        function downloadAgentService() {
            var content = document.getElementById('agent-service-content').textContent;
            var blob = new Blob([content], { type: 'text/plain' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'deployment-agent.service';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    </script>

    <div class="status-msg" style="margin-top:1rem;background:#fff3cd;color:#7a5b00;">
        <x-badge status="offline" /> {{ __('ui.servers.awaiting_connection') }}
        <p class="muted" style="margin:0.4rem 0 0;">{{ __('ui.servers.awaiting_connection_hint') }}</p>
    </div>
    <a href="{{ route('servers.show', $server) }}" class="btn btn-small" style="margin-top:0.8rem;">{{ __('ui.servers.check_connection_button') }}</a>
</x-card>
@else
<x-alert type="success">{{ __('ui.servers.connection_success_body') }}</x-alert>
@endif

<x-card>
    <table>
        <tr><th>server_id</th><td><x-ltr><code>{{ $server->public_id }}</code></x-ltr></td></tr>
        <tr><th>{{ __('ui.servers.type_label') }}</th><td>{{ $server->type ? __('ui.servers.type_' . $server->type) : __('ui.servers.type_unspecified') }}</td></tr>
        <tr><th>{{ __('ui.common.status') }}</th><td>{{ $server->is_active ? __('status.active') : __('status.disabled') }} &mdash;
            @if ($server->isOnline())
                <x-badge status="online" />
            @else
                <x-badge status="offline" />
            @endif
        </td></tr>
        <tr><th>{{ __('ui.common.last_seen') }}</th><td>{{ $server->last_seen_at?->diffForHumans() ?? __('ui.servers.never_polled_yet') }}</td></tr>
        <tr><th>{{ __('ui.common.registered') }}</th><td>{{ $server->created_at->diffForHumans() }}</td></tr>
    </table>

    <h4 style="margin-top:1.2rem;margin-bottom:0.4rem;">{{ __('ui.servers.agent_info_title') }}</h4>
    <table>
        <tr><th>{{ __('ui.common.version') }}</th><td>{{ $server->agent_version ?? __('ui.servers.not_reported_yet') }}</td></tr>
    </table>

    @can('servers.rotate-credentials')
    <form method="POST" action="{{ route('servers.rotate-token', $server) }}" id="rotate-server-token-form" style="margin-top:1rem;display:inline">
        @csrf
    </form>
    <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('rotate-server-token-dialog').showModal()">{{ __('ui.servers.rotate_button') }}</button>
    <x-confirm-dialog
        id="rotate-server-token-dialog"
        form-id="rotate-server-token-form"
        :title="__('ui.servers.rotate_button')"
        :body="__('ui.servers.rotate_confirm')"
        :confirm-label="__('ui.servers.rotate_button')"
    />
    @endcan

    @can('servers.delete')
    <form method="POST" action="{{ route('servers.destroy', $server) }}" id="delete-server-form" style="margin-top:0.6rem;display:inline">
        @csrf
        @method('DELETE')
    </form>
    <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('delete-server-dialog').showModal()">{{ __('ui.servers.delete_button') }}</button>
    <x-confirm-dialog
        id="delete-server-dialog"
        form-id="delete-server-form"
        :title="__('ui.servers.delete_button')"
        :body="__('ui.servers.delete_confirm')"
        :confirm-label="__('ui.servers.delete_button')"
    />
    @endcan
</x-card>

<x-card>
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h3>{{ __('ui.servers.agents_on_server') }}</h3>
        @can('agents.approve')
        <form method="POST" action="{{ route('agents.create-registration', $server) }}">
            @csrf
            <button type="submit" class="btn btn-small">{{ __('ui.servers.register_agent_button') }}</button>
        </form>
        @endcan
    </div>
    <p class="muted">{{ __('ui.servers.agents_optional_note') }}</p>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }} / public_id</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.common.version') }}</th>
            <th>{{ __('ui.common.last_heartbeat') }}</th>
            <th>{{ __('ui.operations.health_title') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($server->agents as $agent)
            <tr>
                <td><a href="{{ route('agents.show', $agent) }}">{{ $agent->name ?? $agent->public_id }}</a></td>
                <td><x-badge :status="$agent->status" /></td>
                <td>{{ $agent->agent_version ?? '—' }}</td>
                <td>{{ $agent->last_seen_at?->diffForHumans() ?? __('status.never') }}</td>
                <td>
                    @if ($agent->health_reported_at)
                        <span class="muted" style="font-size:0.8rem;">
                            {{ __('ui.operations.cpu_load_label') }}: <x-ltr>{{ $agent->cpu_load_percent !== null ? round($agent->cpu_load_percent) . '%' : '—' }}</x-ltr>
                            · {{ __('ui.operations.memory_usage_label') }}: <x-ltr>{{ $agent->memory_usage_percent !== null ? round($agent->memory_usage_percent) . '%' : '—' }}</x-ltr>
                            · {{ __('ui.operations.disk_usage_label') }}: <x-ltr>{{ $agent->disk_usage_percent !== null ? round($agent->disk_usage_percent) . '%' : '—' }}</x-ltr>
                        </span>
                    @else
                        <span class="muted">{{ __('ui.operations.health_not_reported_yet') }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5"><x-empty-state>{{ __('ui.servers.no_agents') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

{{-- Phase 4-K Task 7: Server Detail Operations --}}
<x-card :title="__('ui.operations.recent_operations_title')">
    @can('operations.execute')
        @if ($operationsAgent)
            <form method="POST" action="{{ route('operations.store', $server) }}" style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:0.8rem;">
                @csrf
                <div style="min-width:220px;">
                    <label for="operation_type" style="margin-top:0;">{{ __('ui.operations.title') }}</label>
                    <select id="operation_type" name="operation_type">
                        <optgroup label="{{ __('ui.operations.group_server') }}">
                            @foreach ($serverOperationTypes as $type)
                                <option value="{{ $type }}">{{ __('ui.operations.type_' . $type) }}</option>
                            @endforeach
                        </optgroup>
                        @if ($operationsProject)
                        <optgroup label="{{ __('ui.operations.group_laravel') }}">
                            @foreach ($laravelOperationTypes as $type)
                                <option value="{{ $type }}">{{ __('ui.operations.type_' . $type) }}</option>
                            @endforeach
                        </optgroup>
                        @endif
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-small">{{ __('ui.operations.execute_button') }}</button>
            </form>
            @unless ($operationsProject)
                <p class="muted">{{ __('ui.operations.no_project_notice') }}</p>
            @endunless
        @else
            <x-alert type="error">{{ __('ui.operations.no_agent_notice') }}</x-alert>
        @endif
    @endcan

    <table>
        <thead><tr>
            <th>{{ __('ui.operations.title') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.common.created') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($recentOperations as $operation)
            <tr>
                <td>{{ __('ui.operations.type_' . $operation->operation_type) }}</td>
                <td><x-badge :status="$operation->status" /></td>
                <td>{{ $operation->created_at->diffForHumans() }}</td>
                <td><a href="{{ route('operations.show', $operation) }}" class="btn btn-small">{{ __('ui.operations.view_details_button') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="4"><x-empty-state>{{ __('ui.operations.no_operations_yet') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

<x-card :title="__('ui.discovery.section_title')">
    <p class="muted">{{ __('ui.discovery.intro') }}</p>

    @if ($discoveryAgent)
        <form method="POST" action="{{ route('discovery.scans.store', $server) }}" style="margin-top:0.6rem;">
            @csrf
            <label for="workspace_path">{{ __('ui.discovery.workspace_path_label') }}</label>
            <input type="text" id="workspace_path" name="workspace_path" required dir="ltr" style="text-align:left;" placeholder="{{ __('ui.discovery.workspace_path_placeholder') }}">
            <button type="submit" class="btn btn-primary btn-small" style="margin-top:0.6rem;">{{ __('ui.discovery.scan_button') }}</button>
        </form>
    @else
        <x-alert type="error">{{ __('ui.discovery.needs_agent_notice') }}</x-alert>
    @endif

    @if ($latestDiscoveryJob)
        <div style="margin-top:1rem;">
            <x-badge :status="$latestDiscoveryJob->status" />
            <span class="muted">{{ $latestDiscoveryJob->workspace_path }} — {{ $latestDiscoveryJob->created_at->diffForHumans() }}</span>

            @if ($latestDiscoveryJob->status === \App\Models\DiscoveryJob::STATUS_FAILED)
                <x-alert type="error" style="margin-top:0.6rem;">{{ $latestDiscoveryJob->error_message }}</x-alert>
            @elseif ($latestDiscoveryJob->status === \App\Models\DiscoveryJob::STATUS_SUCCEEDED)
                <h4 style="margin-top:1rem;margin-bottom:0.4rem;">{{ __('ui.discovery.detected_projects_title') }}</h4>
                @php $detected = $latestDiscoveryJob->result ?? []; @endphp
                @if (empty($detected))
                    <x-empty-state>{{ __('ui.discovery.no_projects_detected') }}</x-empty-state>
                @else
                    <table>
                        <thead><tr>
                            <th>{{ __('ui.common.name') }}</th>
                            <th>{{ __('ui.discovery.path_column') }}</th>
                            <th>{{ __('ui.common.repository') }}</th>
                            <th>{{ __('ui.common.branch') }}</th>
                            <th>{{ __('ui.discovery.last_commit_column') }}</th>
                            <th>{{ __('ui.common.action') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($detected as $found)
                            <tr>
                                <td>{{ $found['name'] ?? '—' }} <span class="muted">({{ $found['type'] ?? __('ui.discovery.type_unknown') }})</span></td>
                                <td><x-ltr style="font-size:0.82rem;">{{ $found['path'] ?? '—' }}</x-ltr></td>
                                <td><x-ltr>{{ $found['git']['repository'] ?? '—' }}</x-ltr></td>
                                <td>{{ $found['git']['branch'] ?? '—' }}</td>
                                <td><x-ltr><code>{{ ($found['git']['commit_hash'] ?? null) ? substr($found['git']['commit_hash'], 0, 10) : '—' }}</code></x-ltr></td>
                                <td>
                                    <a class="btn btn-small" href="{{ route('projects.create', [
                                        'discovered_name' => $found['name'] ?? null,
                                        'discovered_path' => $found['path'] ?? null,
                                        'discovered_repository' => $found['git']['repository'] ?? null,
                                        'discovered_branch' => $found['git']['branch'] ?? null,
                                        'discovered_commit' => $found['git']['commit_hash'] ?? null,
                                        'discovered_framework_version' => $found['framework_version'] ?? null,
                                        'discovered_type' => $found['type'] ?? null,
                                    ]) }}">{{ __('ui.discovery.use_project_button') }}</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            @endif
        </div>
    @else
        <p class="muted" style="margin-top:0.6rem;">{{ __('ui.discovery.no_scan_yet') }}</p>
    @endif
</x-card>

<x-card :title="__('ui.servers.targets_title')">
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }}</th>
            <th>{{ __('ui.servers.target_environment') }}</th>
            <th>{{ __('ui.common.status') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($server->deploymentTargets as $target)
            <tr>
                <td>{{ $target->name }}</td>
                <td>{{ $target->environment->name }} ({{ $target->environment->project->name }})</td>
                <td><x-badge status="{{ $target->is_active ? 'active' : 'disabled' }}" /></td>
            </tr>
        @empty
            <tr><td colspan="3"><x-empty-state>{{ __('ui.servers.no_targets') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

<x-card :title="__('ui.servers.projects_on_server')">
    <table>
        <thead><tr>
            <th>{{ __('ui.common.name') }}</th>
            <th>{{ __('ui.common.repository') }}</th>
            <th>{{ __('ui.common.branch') }}</th>
            <th>{{ __('ui.common.environment') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($server->projects as $project)
            <tr>
                <td><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></td>
                <td><x-ltr>{{ $project->repository }}</x-ltr></td>
                <td>{{ $project->branch }}</td>
                <td>{{ $project->environment }}</td>
            </tr>
        @empty
            <tr><td colspan="4"><x-empty-state>{{ __('ui.servers.no_projects') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
