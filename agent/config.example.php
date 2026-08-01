<?php

/**
 * Copy this file to config.php and fill in real values.
 * config.php must NEVER be committed to git or shipped in any repo image —
 * it holds this server's credentials. Keep it out of version control
 * (already covered by .gitignore) and restrict it to 0600 permissions.
 *
 * All values below are placeholders. Nothing here is a real server,
 * host, credential, or path.
 */

return [
    // Base URL of the Deployment Service this agent reports to.
    'service_base_url' => 'https://CHANGE-ME.example.invalid',

    // Identifier for this server, issued by the Deployment Service when
    // the server was registered in its dashboard.
    'server_id' => 'CHANGE_ME_SERVER_ID',

    // Bearer token issued by the Deployment Service for this server.
    // Used for authentication (identifies the caller).
    'agent_token' => 'CHANGE_ME_AGENT_TOKEN',

    // Shared HMAC secret issued by the Deployment Service for this server.
    // Used for request/response signing (proves the caller/payload was not
    // tampered with). Deliberately a separate secret from agent_token so a
    // leak of one does not compromise the other.
    'signing_secret' => 'CHANGE_ME_SIGNING_SECRET',

    // How often (seconds) to poll the service for new jobs.
    'poll_interval_seconds' => 15,

    // HTTP timeout for each request to the service.
    'request_timeout_seconds' => 10,

    // Max seconds a single pipeline step may run before being killed.
    'default_step_timeout_seconds' => 900,

    // Local filesystem mapping. The service only ever refers to a project
    // by its project_key — it never knows or stores this server's paths.
    // This keeps all server-specific filesystem/layout information local
    // to the server it describes, instead of centralized and hardcoded.
    'projects' => [
        // 'project_key' => [
        //     'working_directory' => '/home/example/public_html',
        //     'php_binary' => 'php',
        //
        //     // --- deployment_recipe-only settings below (see
        //     // RecipeStepRunner). A project still using the plain
        //     // `pipeline` form never reads any of these. ---
        //
        //     // For a recipe project, working_directory (above) means the
        //     // app ROOT, not a single checkout — the agent creates
        //     // working_directory/releases/<id> and working_directory/current
        //     // under it (see ReleaseManager). Never set by the Hub.
        //
        //     // How many seconds between heartbeat calls to the Hub while a
        //     // single recipe step is still running (renews the
        //     // deployment's lease — see deployments:detect-stalled).
        //     'job_heartbeat_interval_seconds' => 30,
        //
        //     // How many releases (including the currently active one) to
        //     // keep on disk — activate_release deletes anything older.
        //     'release_retention_count' => 5,
        //
        //     // Defense in depth for restart_service/verify_service_active
        //     // (ServiceAllowlist): even a compromised or buggy Hub can
        //     // never make this agent restart a systemd unit outside this
        //     // list, regardless of what a recipe asks for.
        //     'allowed_services' => ['example-api.service', 'example-web.service'],
        // ],
    ],

    // Where the agent writes its own operational log (not deployment logs,
    // which are streamed to the service).
    'log_dir' => __DIR__ . '/storage/logs',

    // Phase 4-H, Local Discovery — strictly opt-in. Leave commented out
    // (or empty) to keep this agent behaving exactly as it does today:
    // absent means the discovery poll never runs, no code path is
    // reachable, and nothing changes for legacy agents. Set to an
    // absolute path (e.g. '/home/example/www') to let this agent scan
    // that folder's immediate subdirectories on request and report back
    // only structured metadata — project name/path/type/framework
    // version and sanitized git info. Never reads or sends .env files,
    // passwords, tokens, or SSH keys.
    // 'workspace_root' => '/home/example/www',
];
