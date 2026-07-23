@extends('layouts.app')

@section('title', __('ui.servers.create_title'))

@section('content')
<h1>{{ __('ui.servers.create_title') }}</h1>

<x-wizard-steps :current="1" :labels="[
    __('ui.servers.wizard_step1'),
    __('ui.servers.wizard_step2'),
    __('ui.servers.wizard_step3'),
    __('ui.servers.wizard_step4'),
    __('ui.servers.wizard_step5'),
]" />

<p class="muted">{{ __('ui.servers.create_intro') }}</p>

<x-card style="max-width: 500px;">
    <form method="POST" action="{{ route('servers.store') }}">
        @csrf
        <label for="name">{{ __('ui.common.name') }}</label>
        <input type="text" id="name" name="name" placeholder="{{ __('ui.servers.name_placeholder') }}" required autofocus>

        <label for="type">{{ __('ui.servers.type_label') }} ({{ __('ui.common.optional') }})</label>
        <select id="type" name="type">
            <option value="">—</option>
            <option value="vps">{{ __('ui.servers.type_vps') }}</option>
            <option value="cpanel">{{ __('ui.servers.type_cpanel') }}</option>
            <option value="shared_hosting">{{ __('ui.servers.type_shared_hosting') }}</option>
            <option value="docker">{{ __('ui.servers.type_docker') }}</option>
        </select>

        <label for="description">{{ __('ui.common.description') }} ({{ __('ui.common.optional') }})</label>
        <input type="text" id="description" name="description" placeholder="{{ __('ui.servers.description_placeholder') }}">

        <button type="submit" class="btn btn-primary" style="margin-top: 1.2rem;">{{ __('ui.servers.register_submit') }}</button>
    </form>
</x-card>
@endsection
