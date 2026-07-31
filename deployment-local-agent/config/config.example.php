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
];
