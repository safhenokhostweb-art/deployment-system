@extends('layouts.app')

@section('title', __('ui.auth.title'))

@section('content')
<x-card>
    <h2>{{ __('nav.brand') }}</h2>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">{{ __('ui.common.email') }}</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>

        <label for="password">{{ __('ui.common.password') }}</label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="btn btn-primary" style="margin-top: 1.2rem; width: 100%;">{{ __('ui.auth.submit') }}</button>
    </form>
</x-card>
@endsection
