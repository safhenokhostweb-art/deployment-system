<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Deployment Service')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        :root { color-scheme: light dark; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f5f6f8; color: #1c1e21; }
        header { background: #1c2733; color: #fff; padding: 0.9rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        header a { color: #fff; text-decoration: none; font-weight: 600; }
        nav a { color: #cfd8e3; text-decoration: none; margin-right: 1rem; font-size: 0.9rem; }
        nav a:hover { color: #fff; }
        main { max-width: 1100px; margin: 1.5rem auto; padding: 0 1.5rem; }
        .card { background: #fff; border: 1px solid #e1e4e8; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.25rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid #eee; vertical-align: top; }
        th { color: #57606a; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; }
        .badge { display: inline-block; padding: 0.15rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .badge-pending_approval { background: #fff3cd; color: #7a5b00; }
        .badge-queued { background: #dbe4ff; color: #2b3a8f; }
        .badge-running { background: #cdeaff; color: #045d8a; }
        .badge-succeeded { background: #d4f7dc; color: #14622e; }
        .badge-failed { background: #ffd7d7; color: #8a1f1f; }
        .badge-cancelled { background: #eee; color: #555; }
        .btn { display: inline-block; padding: 0.45rem 0.9rem; border-radius: 6px; border: 1px solid #d0d7de; background: #fff; cursor: pointer; font-size: 0.85rem; text-decoration: none; color: #1c1e21; }
        .btn-primary { background: #1c2733; color: #fff; border-color: #1c2733; }
        .btn-danger { background: #a41e1e; color: #fff; border-color: #a41e1e; }
        .btn-small { padding: 0.25rem 0.6rem; font-size: 0.78rem; }
        input[type=text], input[type=email], input[type=password], textarea, select { width: 100%; padding: 0.5rem; border: 1px solid #d0d7de; border-radius: 6px; box-sizing: border-box; font-family: inherit; }
        label { display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.3rem; margin-top: 0.9rem; }
        .status-msg { background: #d4f7dc; color: #14622e; padding: 0.6rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .error-msg { background: #ffd7d7; color: #8a1f1f; padding: 0.6rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .secret-box { background: #1c2733; color: #d4f7dc; font-family: monospace; padding: 0.9rem; border-radius: 6px; word-break: break-all; margin-bottom: 0.6rem; }
        pre { background: #0d1117; color: #e6edf3; padding: 0.9rem; border-radius: 6px; overflow-x: auto; font-size: 0.82rem; }
        .muted { color: #6a737d; font-size: 0.85rem; }
    </style>
</head>
<body>
@auth
<header>
    <a href="{{ route('dashboard.index') }}">Deployment Service</a>
    <nav>
        <a href="{{ route('dashboard.index') }}">Dashboard</a>
        <a href="{{ route('projects.index') }}">Projects</a>
        <a href="{{ route('servers.index') }}">Servers</a>
        <form action="{{ route('logout') }}" method="POST" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-small" style="background:transparent;border-color:#57606a;color:#cfd8e3;">Log out</button>
        </form>
    </nav>
</header>
@endauth
<main>
    @if (session('status'))
        <div class="status-msg">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="error-msg">
            <ul style="margin:0;padding-left:1.1rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
