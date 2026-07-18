@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<div class="card" style="max-width: 380px; margin: 3rem auto;">
    <h2>Deployment Service</h2>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="btn btn-primary" style="margin-top: 1.2rem; width: 100%;">Sign in</button>
    </form>
</div>
@endsection
