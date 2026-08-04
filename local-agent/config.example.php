<?php

/**
 * Copy this file to config.php and fill in real values — or run
 * `php agent.php install --hub=... --id=... --token=...` to generate it
 * automatically from a registration token issued in the Hub's
 * "Local Agents" page.
 *
 * config.php must NEVER be committed to git — it holds this machine's
 * credentials. Keep it out of version control and restrict it to 0600
 * permissions (the installer does this for you).
 *
 * All values below are placeholders. Nothing here is a real Hub, host,
 * credential, or path.
 */

return [
    // Base URL of the Deployment Hub this agent reports to.
    'hub_url' => 'https://CHANGE-ME.example.invalid',

    // This Local Agent's public_id, issued by the Hub when it was
    // registered in the "Local Agents" page.
    'local_agent_id' => 'CHANGE_ME_LOCAL_AGENT_ID',

    // Bearer token issued by the Hub for this Local Agent. Used for
    // authentication (identifies the caller).
    'agent_token' => 'CHANGE_ME_AGENT_TOKEN',

    // Shared HMAC secret issued by the Hub for this Local Agent. Used for
    // request signing (proves the caller/payload was not tampered with).
    // Deliberately a separate secret from agent_token so a leak of one
    // does not compromise the other.
    'signing_secret' => 'CHANGE_ME_SIGNING_SECRET',

    // How often (seconds) to poll the Hub for a publish/rollback request
    // — also the file-watcher's cadence (Phase 5.2): every cycle, each
    // configured project below is checked with `git status` and any
    // change is reported to the Hub. No separate interval, no extra
    // dependency (no filesystem-event extension needed).
    'poll_interval_seconds' => 15,

    // HTTP timeout for each request to the Hub.
    'request_timeout_seconds' => 10,

    // Which local checkouts this agent may publish from is no longer
    // configured here — it is downloaded from the Hub's Local Project
    // Mappings (Local Agent's page -> Project mappings) and cached
    // locally at `cache_path` below, so the agent keeps working from the
    // last good download if the Hub is briefly unreachable. Downloaded
    // on startup, then re-downloaded only when `project_config_ttl_seconds`
    // has elapsed, the Hub reports a changed config_version on a
    // heartbeat, or an Admin queues a "Force Config Reload" — NOT on
    // every poll cycle. See ProjectConfigSyncScheduler/syncProjectConfig()
    // in agent.php.
    //
    // `projects` below is a LEGACY, COMPATIBILITY-ONLY fallback: it is
    // only ever used if the Hub cannot be reached AND no local cache
    // exists yet (e.g. this agent's very first run while offline). Leave
    // it empty unless you have a specific reason to pre-seed a mapping
    // before the agent has synced once.
    // Phase 5.2: to stop specific paths from ever being watched/staged
    // regardless of the Hub's Deployment Mode, add a
    // `.local-agent-ignore` file at the root of that project's
    // local_path (one pattern per line, same shape as .gitignore —
    // `node_modules/`, `*.log`, `.env`). This is enforced by the agent
    // itself before every report/commit, not by git's own ignore
    // mechanism, so it still protects a path that was already committed
    // once by mistake.
    'projects' => [
        // 'project_key' => [
        //     'local_path' => 'C:\\laragon\\www\\example-project',
        //     'remote_name' => 'origin',
        //     'branch' => 'main',
        // ],
    ],

    // Where this agent writes its own operational log.
    'log_dir' => __DIR__ . '/storage/logs',

    // Optional: where the downloaded project configuration is cached.
    // Defaults to a `cache/projects.json` file next to `log_dir` when
    // omitted. Never contains agent_token/signing_secret — project
    // metadata only.
    // 'cache_path' => __DIR__ . '/storage/cache/projects.json',

    // Minimum time (seconds) between two real Hub round trips for project
    // config, outside of the version-change/reload triggers described
    // above. Lower = the agent notices a Hub-side mapping edit sooner on
    // its own; higher = fewer requests. 5-10 minutes (300-600) is a
    // reasonable default — there is rarely a reason to go below ~60.
    'project_config_ttl_seconds' => 300,

    // Root-cause fix: per-command git timeouts. A command that exceeds
    // its timeout is terminated (its whole process tree, not just the
    // one PID — see GitManager) and reported to the Hub as a distinct
    // `timed_out` outcome, never left to hang the agent's main loop (and
    // with it, its own heartbeat — see job_heartbeat_interval_seconds
    // below for how a *legitimately* slow command avoids being mistaken
    // for a hung one anyway).
    'git_status_timeout_seconds' => 30,
    'git_add_timeout_seconds' => 120,
    'git_commit_timeout_seconds' => 60,
    'git_push_timeout_seconds' => 180,
    'git_pull_timeout_seconds' => 180,

    // How often (seconds), while a single git command is still running,
    // the agent pings the Hub with a Job-specific heartbeat — renewing
    // that PublishJob's lease so a slow-but-alive `git add`/`push` is
    // never mistaken by deployments:recover-stale for an abandoned one.
    // Keep comfortably below the Hub's own publish_job_lease_seconds
    // (config/deployment.php, default 120s) — 20-30s leaves several
    // heartbeats' worth of margin.
    'job_heartbeat_interval_seconds' => 25,

    // Hard, whole-job ceiling independent of any single command's own
    // timeout above — catches a publish/rollback that cumulatively ran
    // too long even though no individual step exceeded its own limit.
    // Checked between steps only, never mid-command.
    'job_max_runtime_seconds' => 600,

    // Root-cause fix (v1.4.1): a Windows Scheduled Task does NOT
    // necessarily inherit the same PATH an interactive PowerShell/terminal
    // session has — confirmed on this project's own Laragon dev machine,
    // where `composer`/`npm`/`php` all resolve fine from an open terminal
    // but the Task Scheduler's own process environment lacks Laragon's
    // PATH additions entirely, causing every publish to fail with
    // local_composer_failed ("Failed to start process for: composer
    // install ..."). If that ever happens to you (composer/npm work from
    // a terminal but the agent still can't find them), set the two
    // options below to the *exact*, absolute executable(s) — no PATH
    // lookup involved at all once they're set. Both are plain argv
    // arrays (never a shell string), so paths containing spaces are
    // always safe as-is — do NOT add your own quotes around them.
    //
    // Left unset (the default), the agent falls back to the previous,
    // PATH-dependent behavior (['composer'] / ['npm']) — nothing changes
    // for an agent that never hits this.

    // Example — Windows / Laragon:
    // 'php_binary' => 'C:\\laragon\\bin\\php\\php-8.3-Win32-vs16-x64\\php.exe',
    // 'composer_command' => [
    //     'C:\\laragon\\bin\\php\\php-8.3-Win32-vs16-x64\\php.exe',
    //     'C:\\laragon\\bin\\composer\\composer.phar',
    // ],
    // 'npm_command' => ['C:\\Program Files\\nodejs\\npm.cmd'],

    // Example — Linux/macOS (only ever needed if this agent's own process
    // — e.g. a systemd user service — has a stripped-down PATH):
    // 'composer_command' => ['/usr/local/bin/composer'],
    // 'npm_command' => ['/usr/bin/npm'],

    // 'php_binary' is only ever consulted when 'composer_command' points
    // at a bare *.phar with no interpreter already in front of it (a
    // single-element array, e.g. ['C:\\laragon\\bin\\composer\\composer.phar'])
    // — Windows cannot execute a .phar file directly. It's ignored in
    // every other case, including the two-element
    // [php_binary, composer.phar] form shown above, which is already
    // fully self-contained.
    // 'php_binary' => 'C:\\laragon\\bin\\php\\php-8.3-Win32-vs16-x64\\php.exe',
    // 'composer_command' => ['C:\\laragon\\bin\\composer\\composer.phar'],

    // Root-cause fix (v1.4.2, isolated build workspace): composer/npm run
    // in a disposable, per-publish directory tree entirely outside any
    // project's own folder — never the real vendor/node_modules — see
    // docs/ARTIFACT_DEPLOYMENT.md §5. Defaults to
    // "%TEMP%\DeploymentLocalAgent\builds" — only worth overriding if
    // %TEMP% is unusually space-constrained or on slow/network storage.
    // 'workspace_base_dir' => 'D:\\DeploymentLocalAgent\\builds',
    // How long (seconds) a leftover workspace from a crashed/killed agent
    // process is kept before the next publish's stale-sweep removes it —
    // the normal path always cleans up its own workspace regardless.
    'workspace_ttl_seconds' => 86400,

    // Root-cause fix (v1.4.2, npm process-tail on Windows): `npm ci`/`npm
    // run build` can finish all of their visible work (including
    // postinstall scripts) and still be a genuinely live OS process for a
    // while afterward — Node's event loop doesn't empty (and the process
    // doesn't exit) until npm's own registry-client keep-alive sockets
    // and its background update-notifier check both wind down, confirmed
    // by direct, instrumented testing (see LocalBuildRunner's own
    // docblock). Rather than force-killing a build that already
    // succeeded, the agent grants it this many EXTRA seconds — on top of
    // the timeout already reached — to exit on its own, but ONLY when
    // npm's own output already shows a genuine completion indicator; an
    // actually-stuck build (no such indicator) is still killed
    // immediately, exactly as before. Set to 0 to disable the grace
    // period entirely and restore the original, unconditional
    // kill-at-timeout behavior.
    'npm_grace_period_seconds' => 120,

    // heartbeat-watchdog.php (its own Scheduled Task,
    // DeploymentLocalAgentWatchdog — see install.ps1) reads these three.
    // Minimum time between two watchdog-initiated restarts — restart-loop
    // protection: if whatever caused the hang recurs immediately after a
    // restart, the watchdog refuses to restart again until this elapses,
    // rather than cycling forever.
    'watchdog_cooldown_seconds' => 300,
    // Name of the Scheduled Task the watchdog stops/restarts. Must match
    // the $TaskName install.ps1 registers the main agent under.
    'watchdog_managed_task_name' => 'DeploymentLocalAgent',
    // Optional: where the watchdog persists its last-restart timestamp.
    // Defaults to a `watchdog-state.json` file next to `log_dir`.
    // 'watchdog_state_path' => __DIR__ . '/storage/watchdog-state.json',
];
