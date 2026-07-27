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

    // Local checkouts this agent may publish from. The Hub only ever
    // refers to a project by its project_key — it never knows or stores
    // this machine's paths, exactly like the Server Agent's own
    // config.php. Every project_key here must also have a matching
    // Local Project Mapping created in the Hub (Local Agent's page ->
    // Project mappings), otherwise the Hub will not offer this agent as
    // a publish target for that project.
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
];
