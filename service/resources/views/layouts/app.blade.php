<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>@yield('title', __('nav.brand')) — {{ __('nav.brand') }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Regular is the weight almost all body text renders in — preloaded
         so it starts fetching before the CSS parser reaches the @font-face
         rule. Medium/SemiBold/Bold are used far more sparingly (headings,
         labels, badges) so they're left to load on demand, not preloaded. --}}
    <link rel="preload" href="/fonts/vazirmatn/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <style>
        /* Phase 4-I: single, centralized font source for the whole panel —
           see docs/PHASE_4I_DEPLOYMENT_EXPERIENCE_REPORT.md §9. Every weight
           the UI actually uses (400/500/600/700) is declared once here;
           no other view/component may declare its own @font-face or
           font-family stack. font-display: swap avoids a blank-text flash
           while the woff2 loads, and each @font-face is scoped to a single
           weight so the browser only fetches the file(s) a given element
           actually renders with (never all four up front). */
        @font-face {
            font-family: 'Vazirmatn';
            src: url('/fonts/vazirmatn/Vazirmatn-Regular.woff2') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Vazirmatn';
            src: url('/fonts/vazirmatn/Vazirmatn-Medium.woff2') format('woff2');
            font-weight: 500;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Vazirmatn';
            src: url('/fonts/vazirmatn/Vazirmatn-SemiBold.woff2') format('woff2');
            font-weight: 600;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Vazirmatn';
            src: url('/fonts/vazirmatn/Vazirmatn-Bold.woff2') format('woff2');
            font-weight: 700;
            font-style: normal;
            font-display: swap;
        }

        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        html, body { font-family: 'Vazirmatn', Tahoma, "Segoe UI", Arial, sans-serif; }
        /* Form controls don't inherit body's font in every browser's UA
           stylesheet by default — stated explicitly rather than relying on
           inheritance, per Task 2's own list (button/input/textarea/select/table). */
        button, input, textarea, select, table { font-family: inherit; }
        body { margin: 0; background: #f5f6f8; color: #1c1e21; }
        .ltr-inline { unicode-bidi: isolate; direction: ltr; display: inline-block; }

        header.topbar { background: #1c2733; color: #fff; padding: 0.9rem 1.5rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        header.topbar a { color: #fff; text-decoration: none; font-weight: 600; }
        .topbar-user { display: flex; align-items: center; gap: 0.9rem; font-size: 0.85rem; color: #cfd8e3; }
        .system-status { font-size: 0.8rem; color: #cfd8e3; }
        .system-status .dot { display: inline-block; width: 0.55rem; height: 0.55rem; border-radius: 50%; background: #2fbf71; margin-inline-end: 0.3rem; }

        .app-shell { display: flex; align-items: flex-start; max-width: 1300px; margin: 0 auto; }
        nav.sidebar { width: 210px; flex-shrink: 0; padding: 1.25rem 0.75rem; }
        nav.sidebar a, nav.sidebar span.disabled { display: block; padding: 0.55rem 0.8rem; margin-bottom: 0.2rem; border-radius: 6px; color: #33404d; text-decoration: none; font-size: 0.9rem; }
        nav.sidebar a:hover { background: #e9edf2; }
        nav.sidebar span.disabled { color: #9aa4af; display: flex; justify-content: space-between; align-items: center; }
        nav.sidebar .soon-badge { font-size: 0.68rem; background: #eee; color: #888; border-radius: 999px; padding: 0.1rem 0.5rem; }

        main { flex: 1; min-width: 0; margin: 1.5rem; padding: 0 0.5rem; }
        main.guest-main { max-width: 380px; margin: 3rem auto; }

        .card { background: #fff; border: 1px solid #e1e4e8; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.25rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { text-align: start; padding: 0.5rem 0.6rem; border-bottom: 1px solid #eee; vertical-align: top; }
        th { color: #57606a; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; }
        .badge { display: inline-block; padding: 0.15rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .badge-pending_approval { background: #fff3cd; color: #7a5b00; }
        .badge-queued { background: #dbe4ff; color: #2b3a8f; }
        .badge-running { background: #cdeaff; color: #045d8a; }
        .badge-succeeded, .badge-online, .badge-approved, .badge-success, .badge-active, .badge-connected, .badge-ready, .badge-configured { background: #d4f7dc; color: #14622e; }
        .badge-failed { background: #ffd7d7; color: #8a1f1f; }
        .badge-cancelled, .badge-offline, .badge-rejected { background: #eee; color: #555; }
        .badge-partial_success { background: #ffe9c7; color: #8a4b00; }
        .badge-disabled { background: #ffd7d7; color: #8a1f1f; }
        .badge-pending { background: #fff3cd; color: #7a5b00; }
        .badge-skipped { background: #eee; color: #888; }
        .badge-not_connected, .badge-not_ready, .badge-not_configured, .badge-no_agent { background: #eee; color: #555; }
        .badge-timed_out { background: #ffe9c7; color: #8a4b00; }
        .badge-abandoned { background: #ffd7d7; color: #8a1f1f; }
        {{-- Root-cause fix, section 8: view-only pseudo-status (never a
             persisted PublishJob::status) for a running job whose lease
             expired but deployments:recover-stale hasn't swept it yet —
             deliberately its own distinct, attention-grabbing color, never
             identical to a healthy "running" badge. --}}
        .badge-awaiting_recovery { background: #ffe0b3; color: #7a3e00; border: 1px solid #cc8400; }

        /* Phase 4-J Task 9: severity is a pure data concept (Notification
           model constants) — this is its only UI expression, a colored dot
           + label, kept independent of the status/badge system above. */
        .severity-dot { display: inline-block; width: 0.6rem; height: 0.6rem; border-radius: 50%; flex-shrink: 0; }
        .severity-success .severity-dot, .severity-success-text { background: #2fbf71; color: #14622e; }
        .severity-info .severity-dot, .severity-info-text { background: #2b7fd6; color: #045d8a; }
        .severity-warning .severity-dot, .severity-warning-text { background: #e0a800; color: #7a5b00; }
        .severity-critical .severity-dot, .severity-critical-text { background: #d64545; color: #8a1f1f; }

        .notification-list { list-style: none; margin: 0; padding: 0; }
        .notification-item { display: flex; gap: 0.7rem; align-items: flex-start; padding: 0.8rem 0; border-bottom: 1px solid #f0f0f0; }
        .notification-item:last-child { border-bottom: none; }
        .notification-item.is-unread { background: #f7faff; margin: 0 -0.5rem; padding-inline: 0.5rem; border-radius: 6px; }
        .notification-item .severity-dot { margin-top: 0.4rem; }
        .notification-item .notification-body { flex: 1; min-width: 0; }
        .notification-item .notification-title { font-weight: 600; }
        .notification-item .notification-actions { display: flex; gap: 0.5rem; align-items: center; flex-shrink: 0; }

        .notification-bell { position: relative; color: #fff; text-decoration: none; font-size: 1.1rem; }
        .notification-bell .bell-count { position: absolute; top: -0.5rem; inset-inline-end: -0.7rem; background: #d64545; color: #fff; border-radius: 999px; font-size: 0.68rem; font-weight: 700; padding: 0.05rem 0.4rem; min-width: 1.1rem; text-align: center; }

        /* Phase 4-J Task 5: <details>/<summary> gives a click-to-toggle
           dropdown with no JS at all — same zero-build-step convention
           already used by details.inline-form below. */
        .notification-bell-wrap { position: relative; list-style: none; cursor: pointer; }
        .notification-bell-wrap summary { list-style: none; }
        .notification-bell-wrap summary::-webkit-details-marker { display: none; }
        .notification-dropdown { position: absolute; inset-inline-end: 0; top: 1.7rem; width: 300px; max-height: 360px; overflow-y: auto; background: #fff; color: #1c1e21; border: 1px solid #e1e4e8; border-radius: 8px; box-shadow: 0 10px 26px rgba(0,0,0,0.18); z-index: 60; padding: 0.3rem 0; }
        .notification-dropdown-item { display: flex; gap: 0.55rem; align-items: flex-start; padding: 0.55rem 0.8rem; border-bottom: 1px solid #f0f0f0; }
        .notification-dropdown-item:last-child { border-bottom: none; }
        .notification-dropdown-item.is-unread { background: #f7faff; }
        .notification-dropdown-item .severity-dot { margin-top: 0.35rem; }
        .notification-dropdown-item .notification-title { font-weight: 600; font-size: 0.85rem; }
        .notification-dropdown-empty { padding: 0.9rem 0.8rem; text-align: center; font-size: 0.85rem; color: #6a737d; }
        .notification-dropdown-viewall { display: block; text-align: center; padding: 0.55rem; font-size: 0.82rem; font-weight: 600; color: #1c2733 !important; border-top: 1px solid #eee; text-decoration: none; }
        .notification-dropdown-viewall:hover { background: #f5f6f8; }

        .pipeline-steps { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 0.6rem 0; }
        .pipeline-step { display: flex; align-items: center; gap: 0.4rem; padding: 0.35rem 0.7rem; border-radius: 999px; background: #f0f2f5; font-size: 0.82rem; }
        .pipeline-step .step-icon { font-weight: 700; }
        .pipeline-step.step-succeeded { background: #d4f7dc; color: #14622e; }
        .pipeline-step.step-failed { background: #ffd7d7; color: #8a1f1f; }
        .pipeline-step.step-running { background: #cdeaff; color: #045d8a; }
        .pipeline-step.step-pending, .pipeline-step.step-skipped { background: #f0f2f5; color: #6a737d; }

        .timeline { list-style: none; margin: 0; padding: 0; }
        .timeline li { position: relative; padding-inline-start: 1.4rem; padding-bottom: 1rem; border-inline-start: 2px solid #e1e4e8; }
        .timeline li:last-child { border-inline-start-color: transparent; padding-bottom: 0; }
        .timeline li::before { content: ''; position: absolute; inset-inline-start: -0.4rem; top: 0.15rem; width: 0.75rem; height: 0.75rem; border-radius: 50%; background: #c8cdd3; }
        .timeline li.tl-succeeded::before { background: #14622e; }
        .timeline li.tl-failed::before { background: #8a1f1f; }
        .timeline li.tl-running::before { background: #045d8a; }
        .timeline .tl-time { font-size: 0.75rem; color: #6a737d; }
        .timeline .tl-label { font-weight: 600; }

        .release-tag { font-family: inherit; direction: ltr; unicode-bidi: isolate; display: inline-block; }
        .live-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 0.6rem; }
        .live-status-grid .live-stat { background: #f7f8fa; border-radius: 6px; padding: 0.6rem 0.8rem; }
        .live-status-grid .live-stat .label { display: block; font-size: 0.75rem; color: #6a737d; margin-bottom: 0.2rem; }
        .btn { display: inline-block; padding: 0.45rem 0.9rem; border-radius: 6px; border: 1px solid #d0d7de; background: #fff; cursor: pointer; font-size: 0.85rem; text-decoration: none; color: #1c1e21; }
        .btn-primary { background: #1c2733; color: #fff; border-color: #1c2733; }
        .btn-danger { background: #a41e1e; color: #fff; border-color: #a41e1e; }
        .btn-small { padding: 0.25rem 0.6rem; font-size: 0.78rem; }
        input[type=text], input[type=email], input[type=password], textarea, select { width: 100%; padding: 0.5rem; border: 1px solid #d0d7de; border-radius: 6px; box-sizing: border-box; }
        label { display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.3rem; margin-top: 0.9rem; }
        .status-msg { background: #d4f7dc; color: #14622e; padding: 0.6rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .error-msg { background: #ffd7d7; color: #8a1f1f; padding: 0.6rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .secret-box { background: #1c2733; color: #d4f7dc; font-family: "Courier New", monospace; direction: ltr; text-align: left; padding: 0.9rem; border-radius: 6px; word-break: break-all; margin-bottom: 0.6rem; }
        pre { background: #0d1117; color: #e6edf3; padding: 0.9rem; border-radius: 6px; overflow-x: auto; font-size: 0.82rem; direction: ltr; text-align: left; }
        code { direction: ltr; unicode-bidi: isolate; }
        .muted { color: #6a737d; font-size: 0.85rem; }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem; }
        .summary-grid .card { margin-bottom: 0; }
        .stat-row { display: flex; justify-content: space-between; align-items: center; padding: 0.3rem 0; font-size: 0.9rem; }
        .stat-row strong { font-size: 1rem; }
        .stat-good { color: #14622e; }
        .stat-bad { color: #8a1f1f; }
        .stat-warn { color: #7a5b00; }
        .stat-info { color: #045d8a; }
        .quick-actions { display: flex; flex-wrap: wrap; gap: 0.6rem; }
        details.inline-form { margin-top: 0.9rem; border-top: 1px solid #eee; padding-top: 0.8rem; }
        details.inline-form summary { cursor: pointer; font-weight: 600; color: #1c2733; }
        .server-pick-list label { display: flex; align-items: center; gap: 0.5rem; padding: 0.45rem 0; border-bottom: 1px solid #f0f0f0; font-weight: normal; margin: 0; }
        .server-pick-list input[type=checkbox] { width: auto; }
        .environment-block { border: 1px solid #e1e4e8; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
        .environment-block:last-child { margin-bottom: 0; }
        .target-row { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #f0f0f0; gap: 0.6rem; flex-wrap: wrap; }
        .target-row:last-child { border-bottom: none; }
        .target-actions { display: flex; gap: 0.4rem; align-items: center; }

        .wizard-steps { display: flex; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
        .wizard-step { display: flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.8rem; border-radius: 999px; background: #f0f2f5; color: #6a737d; font-size: 0.8rem; }
        .wizard-step.active { background: #1c2733; color: #fff; font-weight: 600; }
        .wizard-step.done { background: #d4f7dc; color: #14622e; }
        .wizard-step-number { display: inline-flex; align-items: center; justify-content: center; width: 1.3rem; height: 1.3rem; border-radius: 50%; background: rgba(0,0,0,0.15); font-size: 0.72rem; flex-shrink: 0; }
        .install-steps { list-style: none; margin: 0; padding: 0; counter-reset: install-step; }
        .install-steps li { counter-increment: install-step; padding: 0.6rem 0 0.6rem 0; padding-inline-start: 2.2rem; position: relative; border-bottom: 1px solid #f0f0f0; }
        .install-steps li:last-child { border-bottom: none; }
        .install-steps li::before { content: counter(install-step); position: absolute; inset-inline-start: 0; top: 0.5rem; width: 1.6rem; height: 1.6rem; border-radius: 50%; background: #1c2733; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600; }

        dialog.confirm-dialog { border: none; border-radius: 8px; padding: 1.25rem; max-width: 420px; width: 90%; }
        dialog.confirm-dialog::backdrop { background: rgba(0,0,0,0.45); }
        dialog.confirm-dialog h3 { margin-top: 0; }
        .confirm-dialog-actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1rem; }

        @media (max-width: 820px) {
            .app-shell { flex-direction: column; }
            nav.sidebar { width: 100%; display: flex; flex-wrap: wrap; gap: 0.3rem; padding: 0.75rem; }
            nav.sidebar a, nav.sidebar span.disabled { margin-bottom: 0; }
            /* Task 10: a table stays fully legible (no squished columns) by
               scrolling horizontally inside its own card, rather than
               shrinking its columns — the card, not the whole page, scrolls. */
            .card { overflow-x: auto; }
            .card table { min-width: 480px; }
            .live-status-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 480px) {
            main { margin: 0.75rem; padding: 0; }
            .live-status-grid { grid-template-columns: 1fr; }
            .pipeline-steps { gap: 0.35rem; }
        }
    </style>
</head>
<body>
@auth
<header class="topbar">
    <a href="{{ route('dashboard.index') }}">{{ __('nav.brand') }}</a>
    <div class="topbar-user">
        @php
            $onlineServers = \App\Models\Server::with('agents')->get()->filter->isOnline()->count();
            $totalServers = \App\Models\Server::count();
            // Task 6: single indexed COUNT — same (user_id, read_at) index
            // the notifications list itself relies on — computed once per
            // page load, never a user's own notifications leaking anyone
            // else's (scoped through User::notifications(), not a raw query).
            $unreadNotificationsCount = auth()->user()->notifications()->unread()->count();
            // Task 5: a small, capped raw fetch (not a per-notification
            // query) run through the same Task 3 authorization filter as
            // the full /notifications list, so the preview never shows text
            // about a resource the viewer is no longer allowed to see.
            // Skipped entirely while already on the notifications list
            // itself — showing a second, unfiltered copy of the user's
            // notifications next to the page's own filtered list would
            // just be redundant (and confusing, since the two lists
            // wouldn't agree once a filter is applied).
            $onNotificationsPage = request()->routeIs('notifications.*');
            $recentNotifications = $onNotificationsPage
                ? collect()
                : app(\App\Support\Notification\NotificationVisibilityFilter::class)
                    ->filterVisible(auth()->user(), auth()->user()->notifications()->take(20)->get())
                    ->take(5);
        @endphp
        <span class="system-status"><span class="dot"></span>{{ __('nav.servers_online', ['online' => $onlineServers, 'total' => $totalServers]) }}</span>
        <details class="notification-bell-wrap">
            <summary class="notification-bell" title="{{ __('nav.notifications') }}">
                🔔
                @if ($unreadNotificationsCount > 0)
                    <span class="bell-count">{{ $unreadNotificationsCount > 99 ? '99+' : $unreadNotificationsCount }}</span>
                @endif
            </summary>
            <div class="notification-dropdown">
                @if ($onNotificationsPage)
                    <div class="notification-dropdown-empty">{{ __('ui.notifications.viewing_full_list') }}</div>
                @else
                    @forelse ($recentNotifications as $notification)
                        <div class="notification-dropdown-item severity-{{ $notification->severity }} {{ $notification->isRead() ? '' : 'is-unread' }}">
                            <span class="severity-dot"></span>
                            <div class="notification-body">
                                <div class="notification-title">{{ $notification->title }}</div>
                                <span class="muted" style="font-size:0.75rem;">{{ $notification->created_at->diffForHumans() }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="notification-dropdown-empty">{{ __('ui.notifications.no_notifications') }}</div>
                    @endforelse
                @endif
                <a href="{{ route('notifications.index') }}" class="notification-dropdown-viewall">{{ __('ui.notifications.view_all_link') }}</a>
            </div>
        </details>
        <span>{{ auth()->user()->name }}</span>
        <form action="{{ route('logout') }}" method="POST" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-small" style="background:transparent;border-color:#57606a;color:#cfd8e3;">{{ __('nav.logout') }}</button>
        </form>
    </div>
</header>
<div class="app-shell">
    <nav class="sidebar">
        <a href="{{ route('dashboard.index') }}">{{ __('nav.dashboard') }}</a>
        @can('projects.view')
        <a href="{{ route('projects.index') }}">{{ __('nav.projects') }}</a>
        @endcan
        @can('servers.view')
        <a href="{{ route('servers.index') }}">{{ __('nav.servers') }}</a>
        @endcan
        @can('agents.view')
        <a href="{{ route('agents.index') }}">{{ __('nav.agents') }}</a>
        @endcan
        @can('local_agents.view')
        <a href="{{ route('local-agents.index') }}">{{ __('nav.local_agents') }}</a>
        <a href="{{ route('publish.history') }}">{{ __('nav.publish_history') }}</a>
        @endcan
        @can('local_agents.manage')
        <a href="{{ route('local-agents.setup') }}">{{ __('nav.local_agent_setup') }}</a>
        @endcan
        @can('github.view')
        <a href="{{ route('github.accounts.index') }}">{{ __('nav.github') }}</a>
        @endcan
        @can('deployments.view')
        <a href="{{ route('dashboard.index') }}#recent-deployments">{{ __('nav.deployments') }}</a>
        @endcan
        @can('operations.view')
        <a href="{{ route('operations.index') }}">{{ __('nav.operations') }}</a>
        @endcan
        @can('projects.view')
        <a href="{{ route('readiness.index') }}">{{ __('nav.readiness') }}</a>
        @endcan
        @can('users.manage')
        <a href="{{ route('users.index') }}">{{ __('nav.users') }}</a>
        @endcan
        {{-- Task 6: no @can wrapper — a personal inbox every authenticated
             user has, same as the Dashboard link above it, not a
             role/resource-gated section. --}}
        <a href="{{ route('notifications.index') }}">{{ __('nav.notifications') }}</a>
        <span class="disabled">{{ __('nav.reports') }} <span class="soon-badge">{{ __('nav.coming_soon') }}</span></span>
        <span class="disabled">{{ __('nav.settings') }} <span class="soon-badge">{{ __('nav.coming_soon') }}</span></span>
    </nav>
    <main>
        @if (session('status'))
            <x-alert type="success">{{ session('status') }}</x-alert>
        @endif
        @if ($errors->any())
            <x-alert type="error">
                <ul style="margin:0;padding-inline-start:1.1rem;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif
        @yield('content')
    </main>
</div>
@endauth
@guest
<main class="guest-main">
    @if ($errors->any())
        <x-alert type="error">
            <ul style="margin:0;padding-inline-start:1.1rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif
    @yield('content')
</main>
@endguest
</body>
</html>
