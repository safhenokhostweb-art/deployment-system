@extends('layouts.app')

@section('title', __('ui.projects.create_title'))

@section('content')
<h1>{{ __('ui.projects.create_title') }}</h1>

@if ($servers->isEmpty())
    <x-card>
        <p>{{ __('ui.projects.needs_server_prefix') }} <a href="{{ route('servers.create') }}">{{ __('ui.projects.needs_server_link') }}</a>{{ __('ui.projects.needs_server_suffix') }}</p>
    </x-card>
@else

<div id="wizard-indicator">
    <x-wizard-steps :current="1" :labels="[
        __('ui.wizard.step1_title'),
        __('ui.wizard.step2_title'),
        __('ui.wizard.step3_title'),
        __('ui.wizard.step4_title'),
        __('ui.wizard.step5_title'),
        __('ui.wizard.step6_title'),
    ]" />
</div>

<form method="POST" action="{{ route('projects.store') }}" id="project-wizard-form">
    @csrf

    {{-- Step 1: Project info --}}
    <div class="wizard-panel" data-step="1">
        <x-card :title="__('ui.wizard.step1_title')">
            @if (!empty($prefill['name']) || !empty($prefill['path']))
            <x-alert type="success" style="margin-bottom:1rem;">
                {{ __('ui.discovery.detected_notice') }}
                <table style="margin-top:0.5rem;">
                    @if ($prefill['path'])
                    <tr><th>{{ __('ui.discovery.detected_path_label') }}</th><td><x-ltr style="font-size:0.85rem;">{{ $prefill['path'] }}</x-ltr></td></tr>
                    @endif
                    @if ($prefill['framework_version'])
                    <tr><th>{{ __('ui.discovery.detected_framework_label') }}</th><td><x-ltr>{{ $prefill['framework_version'] }}</x-ltr></td></tr>
                    @endif
                    @if ($prefill['commit'])
                    <tr><th>{{ __('ui.discovery.detected_commit_label') }}</th><td><x-ltr><code>{{ substr($prefill['commit'], 0, 10) }}</code></x-ltr></td></tr>
                    @endif
                </table>
            </x-alert>
            @endif

            <label for="name">{{ __('ui.common.name') }}</label>
            <input type="text" id="name" name="name" value="{{ old('name', $prefill['name'] ?? '') }}" required autofocus>

            <label for="description">{{ __('ui.common.description') }} ({{ __('ui.common.optional') }})</label>
            <textarea id="description" name="description" rows="3">{{ old('description') }}</textarea>
        </x-card>
    </div>

    {{-- Step 2: GitHub --}}
    <div class="wizard-panel" data-step="2" style="display:none;">
        <x-card :title="__('ui.wizard.step2_title')">
            @if ($githubAccounts->isNotEmpty())
                <div id="github-picker">
                    <label for="github_account_id">{{ __('ui.wizard.github_account_label') }}</label>
                    <select id="github_account_id" name="github_account_id">
                        <option value="">—</option>
                        @foreach ($githubAccounts as $account)
                            {{-- Final Phase Task 4: auto-selected only when
                                 it's the sole connected account and a Local
                                 Discovery repository was detected — the
                                 same "unambiguous, otherwise don't guess"
                                 rule Server::soleProject() already follows.
                                 With more than one account, nothing is
                                 pre-selected here (no way to know which one
                                 owns the repository without asking GitHub
                                 first), and the manual-entry fields below
                                 still carry the discovered values either way. --}}
                            <option value="{{ $account->id }}" @selected($githubAccounts->count() === 1 && !empty($prefill['repository']))>{{ $account->account_login }}</option>
                        @endforeach
                    </select>

                    <label>{{ __('ui.wizard.repository_picker_label') }}</label>
                    <input type="text" id="repo-search" placeholder="{{ __('ui.wizard.repository_search_placeholder') }}" dir="ltr" style="text-align:left;">
                    <div id="repo-list" class="server-pick-list" style="max-height:240px;overflow-y:auto;margin-top:0.4rem;"></div>
                    <p id="repo-status" class="muted"></p>

                    <div id="branch-picker-wrap" style="display:none;">
                        <label for="branch-select">{{ __('ui.wizard.branch_picker_label') }}</label>
                        <select id="branch-select"></select>
                        <p id="branch-status" class="muted"></p>
                    </div>
                </div>

                <p class="muted" style="margin-top:0.8rem;" id="toggle-manual-entry-wrap">
                    <a href="#" id="toggle-manual-entry">{{ __('ui.wizard.manual_entry_toggle') }}</a>
                </p>
            @else
                <x-alert type="error">
                    {{ __('ui.wizard.no_github_accounts') }}
                    <a href="{{ route('github.accounts.index') }}">{{ __('ui.wizard.connect_github_link') }}</a>
                </x-alert>
            @endif

            <div id="manual-entry" style="{{ $githubAccounts->isNotEmpty() ? 'display:none;' : '' }}margin-top:0.8rem;">
                <label for="repository">{{ __('ui.common.repository') }} (GitHub)</label>
                <input type="text" id="repository" name="repository" value="{{ old('repository', $prefill['repository'] ?? '') }}" placeholder="{{ __('ui.projects.repository_placeholder') }}" dir="ltr" style="text-align:left;">

                <label for="branch">{{ __('ui.common.branch') }}</label>
                <input type="text" id="branch" name="branch" value="{{ old('branch', $prefill['branch'] ?? 'main') }}" dir="ltr" style="text-align:left;">

                @if ($githubAccounts->isNotEmpty())
                    <p class="muted" style="margin-top:0.4rem;"><a href="#" id="toggle-picker-entry">{{ __('ui.wizard.use_picker_toggle') }}</a></p>
                @endif
            </div>
        </x-card>
    </div>

    {{-- Step 3: Deployment --}}
    <div class="wizard-panel" data-step="3" style="display:none;">
        <x-card :title="__('ui.wizard.step3_title')">
            <label for="server_id">{{ __('ui.common.server') }}</label>
            <select id="server_id" name="server_id" required>
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}">{{ $server->name }}</option>
                @endforeach
            </select>

            <label for="environment_name">{{ __('ui.wizard.environment_name_label') }}</label>
            <input type="text" id="environment_name" name="environment_name" value="{{ old('environment_name', 'Production') }}" required>
            <p class="muted">{{ __('ui.wizard.environment_name_help') }}</p>

            <input type="hidden" id="environment" name="environment" value="production">
        </x-card>
    </div>

    {{-- Step 4: Pipeline --}}
    <div class="wizard-panel" data-step="4" style="display:none;">
        <x-card :title="__('ui.wizard.step4_title')">
            <label for="pipeline_preset">{{ __('ui.wizard.pipeline_preset_label') }}</label>
            <select id="pipeline_preset">
                <option value="laravel" @selected($presetKey === 'laravel')>{{ __('ui.wizard.pipeline_preset_laravel') }}</option>
                <option value="wordpress" @selected($presetKey === 'wordpress')>{{ __('ui.wizard.pipeline_preset_wordpress') }}</option>
                <option value="static" @selected($presetKey === 'static')>{{ __('ui.wizard.pipeline_preset_static') }}</option>
                <option value="custom">{{ __('ui.wizard.pipeline_preset_custom') }}</option>
            </select>

            <label for="pipeline">{{ __('ui.projects.pipeline') }}</label>
            <textarea id="pipeline" name="pipeline" rows="12" dir="ltr" style="text-align:left;" required>{{ old('pipeline', $defaultPipeline) }}</textarea>
            <p class="muted">{{ __('ui.projects.pipeline_help') }}</p>
        </x-card>
    </div>

    {{-- Step 5: Review --}}
    <div class="wizard-panel" data-step="5" style="display:none;">
        <x-card :title="__('ui.wizard.review_title')">
            <table id="wizard-review-table">
                <tr><th>{{ __('ui.wizard.review_name') }}</th><td id="review-name"></td></tr>
                <tr><th>{{ __('ui.common.repository') }}</th><td id="review-repository"></td></tr>
                <tr><th>{{ __('ui.wizard.review_branch') }}</th><td id="review-branch"></td></tr>
                <tr><th>{{ __('ui.wizard.review_server') }}</th><td id="review-server"></td></tr>
                <tr><th>{{ __('ui.wizard.review_environment') }}</th><td id="review-environment"></td></tr>
                <tr><th>{{ __('ui.projects.pipeline') }}</th><td id="review-pipeline"></td></tr>
            </table>
        </x-card>
    </div>

    {{-- Step 6: Create --}}
    <div class="wizard-panel" data-step="6" style="display:none;">
        <x-card :title="__('ui.wizard.step6_title')">
            <label>{{ __('ui.wizard.project_key_label') }}</label>
            <div class="secret-box" id="project-key-preview" style="background:#f0f2f5;color:#1c1e21;"></div>
            <input type="hidden" id="project_key" name="project_key" value="{{ old('project_key') }}">
            <p class="muted"><a href="#" id="toggle-project-key">{{ __('ui.wizard.project_key_manual_toggle') }}</a></p>
            <input type="text" id="project_key_manual" style="display:none;" dir="ltr" placeholder="e.g. saas-platform-production" pattern="[a-z0-9-]+">

            <label>
                <input type="checkbox" name="auto_deploy" value="1" style="width:auto;display:inline-block;" {{ old('auto_deploy') ? 'checked' : '' }}>
                {{ __('ui.projects.auto_deploy_label') }}
            </label>

            <button type="submit" class="btn btn-primary" style="margin-top:1.2rem;">{{ __('ui.wizard.create_button') }}</button>
        </x-card>
    </div>

    <div style="display:flex;justify-content:space-between;margin-top:1rem;">
        <button type="button" id="wizard-back" class="btn" style="display:none;">{{ __('ui.wizard.back_button') }}</button>
        <button type="button" id="wizard-next" class="btn btn-primary" style="margin-inline-start:auto;">{{ __('ui.wizard.next_button') }}</button>
    </div>
</form>

<script>
(function () {
    var totalSteps = 6;
    var current = 1;
    var form = document.getElementById('project-wizard-form');
    var panels = form.querySelectorAll('.wizard-panel');
    var backBtn = document.getElementById('wizard-back');
    var nextBtn = document.getElementById('wizard-next');
    var indicatorSteps = document.querySelectorAll('#wizard-indicator .wizard-step');

    function showStep(n) {
        panels.forEach(function (panel) {
            panel.style.display = (parseInt(panel.dataset.step, 10) === n) ? '' : 'none';
        });
        indicatorSteps.forEach(function (el, index) {
            var stepNum = index + 1;
            el.classList.remove('active', 'done');
            if (stepNum < n) el.classList.add('done');
            if (stepNum === n) el.classList.add('active');
        });
        backBtn.style.display = (n === 1) ? 'none' : '';
        nextBtn.style.display = (n === totalSteps) ? 'none' : '';
        if (n === totalSteps - 1) populateReview();
        current = n;
    }

    function currentPanelValid() {
        var panel = form.querySelector('.wizard-panel[data-step="' + current + '"]');
        var fields = panel.querySelectorAll('input, select, textarea');
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].offsetParent !== null && !fields[i].checkValidity()) {
                fields[i].reportValidity();
                return false;
            }
        }
        // repository/branch inputs are hidden (not "visible") while the
        // GitHub picker is active, so the loop above skips them even
        // though they're the fields actually being submitted.
        if (current === 2) {
            var repoVal = document.getElementById('repository').value.trim();
            var branchVal = document.getElementById('branch').value.trim();
            if (!repoVal || !branchVal) {
                alert('{{ __('ui.wizard.select_repository_first') }}');
                return false;
            }
        }
        return true;
    }

    nextBtn.addEventListener('click', function () {
        if (!currentPanelValid()) return;
        if (current < totalSteps) showStep(current + 1);
    });
    backBtn.addEventListener('click', function () {
        if (current > 1) showStep(current - 1);
    });

    function populateReview() {
        document.getElementById('review-name').textContent = document.getElementById('name').value;
        document.getElementById('review-repository').textContent = document.getElementById('repository').value || '—';
        document.getElementById('review-branch').textContent = document.getElementById('branch').value || '—';
        var serverSelect = document.getElementById('server_id');
        document.getElementById('review-server').textContent = serverSelect.options[serverSelect.selectedIndex] ? serverSelect.options[serverSelect.selectedIndex].text : '—';
        document.getElementById('review-environment').textContent = document.getElementById('environment_name').value;
        try {
            var steps = JSON.parse(document.getElementById('pipeline').value);
            document.getElementById('review-pipeline').textContent = '{{ __('ui.wizard.review_pipeline_steps') }}'.replace(':count', Array.isArray(steps) ? steps.length : 0);
        } catch (e) {
            document.getElementById('review-pipeline').textContent = '—';
        }
        updateProjectKeyPreview();
    }

    // Step 1 -> live project_key preview (Task 7)
    var projectKeyManualMode = false;
    function slugify(text) {
        return text.toString().toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }
    function updateProjectKeyPreview() {
        if (projectKeyManualMode) return;
        var slug = slugify(document.getElementById('name').value) || 'project';
        document.getElementById('project-key-preview').textContent = slug;
        document.getElementById('project_key').value = '';
    }
    document.getElementById('name').addEventListener('input', updateProjectKeyPreview);
    document.getElementById('toggle-project-key').addEventListener('click', function (e) {
        e.preventDefault();
        projectKeyManualMode = !projectKeyManualMode;
        var manualInput = document.getElementById('project_key_manual');
        var preview = document.getElementById('project-key-preview');
        if (projectKeyManualMode) {
            manualInput.style.display = '';
            preview.style.display = 'none';
            manualInput.value = document.getElementById('project_key').value || slugify(document.getElementById('name').value);
        } else {
            manualInput.style.display = 'none';
            preview.style.display = '';
            updateProjectKeyPreview();
        }
    });
    document.getElementById('project_key_manual').addEventListener('input', function () {
        document.getElementById('project_key').value = this.value;
    });

    // Step 3 -> keep legacy hidden 'environment' field in sync
    document.getElementById('environment_name').addEventListener('input', function () {
        document.getElementById('environment').value = slugify(this.value) || 'production';
    });
    document.getElementById('environment').value = slugify(document.getElementById('environment_name').value) || 'production';

    // Step 4 -> pipeline presets
    var presets = @json($pipelinePresets);
    document.getElementById('pipeline_preset').addEventListener('change', function () {
        document.getElementById('pipeline').value = presets[this.value] || presets.custom;
    });

    @if ($githubAccounts->isNotEmpty())
    // Step 2 -> GitHub repository/branch pickers
    var accountSelect = document.getElementById('github_account_id');
    var repoSearch = document.getElementById('repo-search');
    var repoList = document.getElementById('repo-list');
    var repoStatus = document.getElementById('repo-status');
    var branchWrap = document.getElementById('branch-picker-wrap');
    var branchSelect = document.getElementById('branch-select');
    var branchStatus = document.getElementById('branch-status');
    var repositoryInput = document.getElementById('repository');
    var branchInput = document.getElementById('branch');
    var allRepos = [];
    var selectedRepo = null;
    // Final Phase Task 4: when set, the repo list auto-selects the
    // matching entry as soon as it loads, and the branch list prefers
    // this over the repository's own default_branch — the whole point
    // being the user never has to re-pick what Discovery already found.
    var discoveredRepository = @json($prefill['repository'] ?? null);
    var discoveredBranch = @json($prefill['branch'] ?? null);

    function renderRepoList(repos) {
        repoList.innerHTML = '';
        repos.forEach(function (repo) {
            var label = document.createElement('label');
            var isSelected = selectedRepo && selectedRepo.full_name === repo.full_name;
            label.innerHTML = '<input type="radio" name="repo-radio" ' + (isSelected ? 'checked' : '') + '> '
                + repo.full_name + ' '
                + '<span class="badge ' + (repo.private ? 'badge-offline' : 'badge-online') + '" style="margin-inline-start:0.4rem;">'
                + (repo.private ? '{{ __('ui.wizard.visibility_private') }}' : '{{ __('ui.wizard.visibility_public') }}')
                + '</span>';
            label.addEventListener('click', function () { selectRepo(repo); });
            repoList.appendChild(label);
        });
    }

    function selectRepo(repo) {
        selectedRepo = repo;
        repositoryInput.value = repo.full_name;
        renderRepoList(filteredRepos());
        loadBranches(repo);
    }

    function filteredRepos() {
        var term = repoSearch.value.trim().toLowerCase();
        if (!term) return allRepos;
        return allRepos.filter(function (r) { return r.full_name.toLowerCase().indexOf(term) !== -1; });
    }

    repoSearch.addEventListener('input', function () { renderRepoList(filteredRepos()); });

    accountSelect.addEventListener('change', function () {
        var accountId = this.value;
        allRepos = [];
        selectedRepo = null;
        repoList.innerHTML = '';
        branchWrap.style.display = 'none';
        if (!accountId) return;
        repoStatus.textContent = '{{ __('ui.wizard.loading_repositories') }}';
        fetch('/github/accounts/' + accountId + '/repositories')
            .then(function (r) { if (!r.ok) throw new Error('failed'); return r.json(); })
            .then(function (data) {
                allRepos = data.repositories || [];
                repoStatus.textContent = '';
                renderRepoList(allRepos);

                if (discoveredRepository) {
                    var match = allRepos.filter(function (r) { return r.full_name === discoveredRepository; })[0];
                    if (match) selectRepo(match);
                    discoveredRepository = null; // only ever auto-apply once
                }
            })
            .catch(function () { repoStatus.textContent = '{{ __('ui.wizard.repositories_load_failed') }}'; });
    });

    // Auto-select fires the same account-loading path a manual pick would,
    // exactly once, only when the option above was actually pre-selected.
    if (accountSelect.value) {
        accountSelect.dispatchEvent(new Event('change'));
    }

    function loadBranches(repo) {
        var accountId = accountSelect.value;
        branchSelect.innerHTML = '';
        branchStatus.textContent = '{{ __('ui.wizard.loading_branches') }}';
        branchWrap.style.display = '';
        fetch('/github/accounts/' + accountId + '/branches?owner=' + encodeURIComponent(repo.owner) + '&repo=' + encodeURIComponent(repo.name))
            .then(function (r) { if (!r.ok) throw new Error('failed'); return r.json(); })
            .then(function (data) {
                branchStatus.textContent = '';
                var branches = data.branches || [];
                // Prefer the branch Discovery actually found the project
                // checked out on over the repository's generic default —
                // falls back to default_branch when there's no discovered
                // branch, or it doesn't exist in this repo (e.g. it was
                // since deleted upstream).
                var preferredBranch = (discoveredBranch && branches.indexOf(discoveredBranch) !== -1)
                    ? discoveredBranch
                    : repo.default_branch;
                branches.forEach(function (name) {
                    var opt = document.createElement('option');
                    opt.value = name;
                    opt.textContent = name;
                    if (name === preferredBranch) opt.selected = true;
                    branchSelect.appendChild(opt);
                });
                branchInput.value = branchSelect.value;
                discoveredBranch = null; // only ever auto-apply once
            })
            .catch(function () { branchStatus.textContent = '{{ __('ui.wizard.branches_load_failed') }}'; });
    }

    branchSelect.addEventListener('change', function () { branchInput.value = this.value; });

    document.getElementById('toggle-manual-entry').addEventListener('click', function (e) {
        e.preventDefault();
        document.getElementById('github-picker').style.display = 'none';
        document.getElementById('toggle-manual-entry-wrap').style.display = 'none';
        document.getElementById('manual-entry').style.display = '';
        document.getElementById('github_account_id').value = '';
    });
    document.getElementById('toggle-picker-entry').addEventListener('click', function (e) {
        e.preventDefault();
        document.getElementById('manual-entry').style.display = 'none';
        document.getElementById('github-picker').style.display = '';
        document.getElementById('toggle-manual-entry-wrap').style.display = '';
    });
    @endif

    showStep(1);
})();
</script>
@endif
@endsection
