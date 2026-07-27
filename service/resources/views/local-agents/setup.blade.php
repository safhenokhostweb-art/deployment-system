@extends('layouts.app')

@section('title', __('ui.local_agent_setup.title'))

@section('content')
<h1>{{ __('ui.local_agent_setup.title') }}</h1>
<p class="muted">{{ __('ui.local_agent_setup.intro') }}</p>

@if ($isReady)
<x-card style="border-color:#14622e;">
    <p class="stat-good" style="font-size:1.1rem;font-weight:bold;">{{ __('ui.local_agent_setup.ready_title') }}</p>
    <p class="muted">{{ __('ui.local_agent_setup.ready_body') }}</p>
</x-card>
@else
<x-card>
    <p class="muted">{{ __('ui.local_agent_setup.not_ready_body') }}</p>
</x-card>
@endif

<x-card :title="__('ui.local_agent_setup.step1_title')">
    <p>{{ __('ui.local_agent_setup.step1_body') }}</p>
    <ul>
        <li>{{ __('ui.local_agent_setup.step1_check_php') }}</li>
        <li>{{ __('ui.local_agent_setup.step1_check_git') }}</li>
        <li>{{ __('ui.local_agent_setup.step1_check_ssh') }}</li>
        <li>{{ __('ui.local_agent_setup.step1_check_repo') }}</li>
    </ul>
</x-card>

<x-card :title="__('ui.local_agent_setup.step2_title')">
    <p>{{ __('ui.local_agent_setup.step2_body') }}</p>
    <form method="POST" action="{{ route('local-agents.create-registration') }}">
        @csrf
        <button type="submit" class="btn btn-primary">{{ __('ui.local_agents.create_registration_button') }}</button>
    </form>
</x-card>

<x-card :title="__('ui.local_agent_setup.step3_title')">
    <p>{{ __('ui.local_agent_setup.step3_body') }}</p>
</x-card>

<x-card :title="__('ui.local_agent_setup.step4_title')">
    <p>{{ __('ui.local_agent_setup.step4_body') }}</p>
    <a href="{{ route('local-agents.pending') }}" class="btn">{{ __('ui.local_agents.pending_button') }}</a>
</x-card>

<x-card :title="__('ui.local_agent_setup.step5_title')">
    <p>{{ __('ui.local_agent_setup.step5_body') }}</p>
    <a href="{{ route('local-agents.index') }}" class="btn">{{ __('ui.local_agents.all_button') }}</a>
</x-card>

<x-card :title="__('ui.local_agent_setup.step6_title')">
    <p>{{ __('ui.local_agent_setup.step6_body') }}</p>
    <a href="{{ route('projects.index') }}" class="btn">{{ __('ui.projects.title') }}</a>
</x-card>
@endsection
