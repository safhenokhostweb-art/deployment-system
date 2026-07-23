@extends('layouts.app')

@section('title', __('ui.github.title'))

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1>{{ __('ui.github.title') }}</h1>
    @can('github.connect')
    @if (config('services.github_app.slug'))
        <a href="{{ route('github.connect') }}" class="btn btn-primary">{{ __('ui.github.connect_button') }}</a>
    @endif
    @endcan
</div>

{{-- Phase 4-L Task 2: GitHub Setup Wizard checklist. Step 1 is the one
     genuinely manual, unavoidable step (creating the GitHub App itself
     requires the operator's own github.com login) — everything after it
     is already a single click. --}}
<x-card :title="__('ui.github.setup_checklist_title')">
    <div class="pipeline-steps" style="margin-bottom:0.8rem;">
        <span class="pipeline-step step-{{ $appConfigured ? 'succeeded' : 'pending' }}">
            <span class="step-icon">{{ $appConfigured ? '✓' : '○' }}</span> {{ __('ui.github.step_app_configured') }}
        </span>
        <span class="pipeline-step step-{{ $accounts->contains(fn ($a) => $a->isConnected()) ? 'succeeded' : 'pending' }}">
            <span class="step-icon">{{ $accounts->contains(fn ($a) => $a->isConnected()) ? '✓' : '○' }}</span> {{ __('ui.github.step_account_connected') }}
        </span>
        <span class="pipeline-step step-{{ $projectsNeedingGithub->isEmpty() && $accounts->contains(fn ($a) => $a->isConnected()) ? 'succeeded' : 'pending' }}">
            <span class="step-icon">{{ $projectsNeedingGithub->isEmpty() && $accounts->contains(fn ($a) => $a->isConnected()) ? '✓' : '○' }}</span> {{ __('ui.github.step_projects_connected') }}
        </span>
    </div>

    @unless ($appConfigured)
        <x-alert type="error">
            {{ __('ui.github.not_configured_notice') }}
            <ul style="margin:0.5rem 0 0;padding-inline-start:1.2rem;">
                @foreach ($appConfigChecklist as $key => $present)
                    @unless ($present)
                        <li><x-ltr><code>{{ strtoupper('GITHUB_APP_' . $key) }}</code></x-ltr> — {{ __('ui.github.missing_env_key') }}</li>
                    @endunless
                @endforeach
            </ul>
        </x-alert>
    @endunless
</x-card>

<x-card>
    <table>
        <thead><tr>
            <th>{{ __('ui.github.account_name') }}</th>
            <th>{{ __('ui.github.provider') }}</th>
            <th>{{ __('ui.common.status') }}</th>
            <th>{{ __('ui.github.last_tested') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($accounts as $account)
            <tr>
                <td>{{ $account->account_login }}</td>
                <td>GitHub ({{ $account->account_type }})</td>
                <td>
                    @if ($account->isConnected())
                        <x-badge status="online" />
                    @else
                        <x-badge status="offline" />
                    @endif
                </td>
                <td>{{ $account->last_tested_at?->diffForHumans() ?? __('ui.github.never_tested') }}</td>
                <td style="white-space:nowrap;">
                    @if ($account->isConnected())
                        @can('github.connect')
                        <form method="POST" action="{{ route('github.accounts.test-connection', $account) }}" style="display:inline">
                            @csrf
                            <button type="submit" class="btn btn-small">{{ __('ui.github.test_connection_button') }}</button>
                        </form>
                        @endcan
                        @can('github.disconnect')
                        <form method="POST" action="{{ route('github.accounts.disconnect', $account) }}" id="disconnect-github-{{ $account->id }}-form" style="display:inline">
                            @csrf
                        </form>
                        <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('disconnect-github-{{ $account->id }}-dialog').showModal()">{{ __('ui.github.disconnect_button') }}</button>
                        <x-confirm-dialog
                            id="disconnect-github-{{ $account->id }}-dialog"
                            form-id="disconnect-github-{{ $account->id }}-form"
                            :title="__('ui.github.disconnect_button')"
                            :body="__('ui.github.disconnect_confirm')"
                            :confirm-label="__('ui.github.disconnect_button')"
                        />
                        @endcan
                    @endif
                    @can('github.disconnect')
                    <form method="POST" action="{{ route('github.accounts.destroy', $account) }}" id="delete-github-{{ $account->id }}-form" style="display:inline">
                        @csrf
                        @method('DELETE')
                    </form>
                    <button type="button" class="btn btn-danger btn-small" onclick="document.getElementById('delete-github-{{ $account->id }}-dialog').showModal()">{{ __('ui.github.delete_button') }}</button>
                    <x-confirm-dialog
                        id="delete-github-{{ $account->id }}-dialog"
                        form-id="delete-github-{{ $account->id }}-form"
                        :title="__('ui.github.delete_button')"
                        :body="__('ui.github.delete_confirm')"
                        :confirm-label="__('ui.github.delete_button')"
                    />
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="5"><x-empty-state>{{ __('ui.github.no_accounts') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

@if ($projectsNeedingGithub->isNotEmpty())
{{-- Task 2: never re-asks for repository/branch — both already exist on
     the project; the only decision left (only shown when there's more
     than one connected account to choose from) is which account owns it. --}}
<x-card :title="__('ui.github.projects_needing_connection_title')">
    <p class="muted">{{ __('ui.github.projects_needing_connection_intro') }}</p>
    <table>
        <thead><tr>
            <th>{{ __('ui.common.project') }}</th>
            <th>{{ __('ui.common.repository') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @php $connectedAccounts = $accounts->filter(fn ($a) => $a->isConnected()); @endphp
        @foreach ($projectsNeedingGithub as $project)
            <tr>
                <td><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></td>
                <td><x-ltr>{{ $project->repository }}</x-ltr></td>
                <td>
                    <form method="POST" action="{{ route('projects.connect-github', $project) }}" style="display:flex;gap:0.4rem;align-items:center;">
                        @csrf
                        @if ($connectedAccounts->count() > 1)
                            <select name="github_account_id" style="width:auto;">
                                @foreach ($connectedAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->account_login }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="hidden" name="github_account_id" value="{{ $connectedAccounts->first()->id }}">
                        @endif
                        <button type="submit" class="btn btn-small">{{ __('ui.github.connect_project_button') }}</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-card>
@endif
@endsection
