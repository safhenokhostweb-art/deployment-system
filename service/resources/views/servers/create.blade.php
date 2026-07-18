@extends('layouts.app')

@section('title', 'Register server')

@section('content')
<h1>Register a new server</h1>
<p class="muted">This only creates a record and issues credentials. It does not connect to anything — you install the Deployment Agent on that server yourself and paste the credentials shown next into its config.php.</p>

<div class="card" style="max-width: 500px;">
    <form method="POST" action="{{ route('servers.store') }}">
        @csrf
        <label for="name">Name</label>
        <input type="text" id="name" name="name" placeholder="e.g. prod-web-1" required autofocus>

        <label for="description">Description (optional)</label>
        <input type="text" id="description" name="description" placeholder="e.g. cPanel host for saas-platform production">

        <button type="submit" class="btn btn-primary" style="margin-top: 1.2rem;">Register</button>
    </form>
</div>
@endsection
